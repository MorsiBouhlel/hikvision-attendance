<?php

namespace App\Service;

use App\Entity\AttendanceEvent;
use App\Entity\Employee;
use App\Entity\WorkSchedule;
use App\Repository\AttendanceEventRepository;
use App\Repository\EmployeeRepository;
use App\Repository\HolidayRepository;
use App\Repository\LeaveRepository;

class AttendanceService
{
    public function __construct(
        private readonly AttendanceEventRepository $events,
        private readonly EmployeeRepository $employees,
        private readonly HolidayRepository $holidays,
        private readonly LeaveRepository $leaves,
    ) {
    }

    /**
     * Détermine l'entrée/la sortie du jour pour un employé — voir
     * classifyPunches() pour la règle exacte. Sans WorkSchedule assigné:
     * 1er pointage du jour = entrée, dernier = sortie (comportement
     * historique). Avec WorkSchedule: classement fiable via attendanceStatus
     * (si envoyé par la pointeuse) ou fenêtre horaire attendue sinon — gère
     * les pointages multiples dans la journée (pauses, allers-retours) sans
     * les confondre avec l'entrée/la sortie réelles.
     *
     * $isHoliday/$employeeIdsOnLeave peuvent être précalculés par l'appelant
     * (voir dailySummaryForAll) pour éviter une requête par employé.
     */
    public function dailySummary(
        Employee $employee,
        \DateTimeImmutable $date,
        ?bool $isHoliday = null,
        ?array $employeeIdsOnLeave = null,
    ): array {
        $isHoliday ??= $this->holidays->isHoliday($date);
        $employeeIdsOnLeave ??= $this->leaves->employeeIdsOnLeave($date);
        $onLeave = in_array($employee->getId(), $employeeIdsOnLeave, true);

        $events = $this->events->findForEmployeeOnDate($employee, $date);

        return $this->classifyDay($employee, $events, $date, $isHoliday, $onLeave);
    }

    /** @return array<int, array> résumé du jour pour tous les employés actifs (filtré par $departmentId si fourni) */
    public function dailySummaryForAll(\DateTimeImmutable $date, ?int $departmentId = null): array
    {
        $isHoliday = $this->holidays->isHoliday($date);
        $employeeIdsOnLeave = $this->leaves->employeeIdsOnLeave($date);

        return array_map(
            fn (Employee $e) => $this->dailySummary($e, $date, $isHoliday, $employeeIdsOnLeave),
            $this->activeEmployees($departmentId)
        );
    }

    /** @return Employee[] employés actifs n'ayant pas pointé aujourd'hui (hors congé/férié) */
    public function missingToday(): array
    {
        $today = new \DateTimeImmutable('today');

        if ($this->holidays->isHoliday($today)) {
            return [];
        }

        $presentIds = $this->events->employeeIdsPresentOn($today);
        $onLeaveIds = $this->leaves->employeeIdsOnLeave($today);

        return array_filter(
            $this->employees->findActive(),
            fn (Employee $e) => ! in_array($e->getId(), $presentIds, true) && ! in_array($e->getId(), $onLeaveIds, true)
        );
    }

    /**
     * Résumé agrégé par employé actif sur une plage de dates arbitraire:
     * heures travaillées totales, nombre de retards, minutes de retard
     * cumulées, absences. Toutes les données sont préchargées en 3 requêtes
     * bulk (jamais de requête par employé/jour).
     *
     * @return array<int, array>
     */
    public function rangeSummary(\DateTimeImmutable $start, \DateTimeImmutable $end, ?int $departmentId = null): array
    {
        $start = $start->setTime(0, 0, 0);
        $end = $end->setTime(23, 59, 59);

        $eventsByEmployeeAndDay = [];
        foreach ($this->events->findForAllEmployeesBetween($start, $end) as $event) {
            $employeeId = $event->getEmployee()->getId();
            $day = $event->getOccurredAt()->format('Y-m-d');
            $eventsByEmployeeAndDay[$employeeId][$day][] = $event;
        }

        $onLeaveByEmployeeAndDay = [];
        foreach ($this->leaves->findOverlapping($start, $end) as $leave) {
            $employeeId = $leave->getEmployee()->getId();
            $cursor = max($leave->getStartDate(), $start);
            $until = min($leave->getEndDate(), $end);
            while ($cursor <= $until) {
                $onLeaveByEmployeeAndDay[$employeeId][$cursor->format('Y-m-d')] = true;
                $cursor = $cursor->modify('+1 day');
            }
        }

        $holidayDays = array_flip(array_map(
            fn (\DateTimeImmutable $d) => $d->format('Y-m-d'),
            $this->holidays->findDatesBetween($start, $end)
        ));

        $rows = [];
        foreach ($this->activeEmployees($departmentId) as $employee) {
            $workedHours = 0.0;
            $lateCount = 0;
            $lateMinutesTotal = 0;
            $absences = 0;
            $earlyLeaveCount = 0;
            $overtimeInTotal = 0;
            $overtimeOutTotal = 0;

            $cursor = $start;
            while ($cursor <= $end) {
                $day = $cursor->format('Y-m-d');
                $isHoliday = isset($holidayDays[$day]);
                $onLeave = $onLeaveByEmployeeAndDay[$employee->getId()][$day] ?? false;
                $dayEvents = $eventsByEmployeeAndDay[$employee->getId()][$day] ?? [];

                $summary = $this->classifyDay($employee, $dayEvents, $cursor, $isHoliday, $onLeave);

                $workedHours += $summary['worked_hours'] ?? 0.0;
                if ($summary['status'] === 'late') {
                    $lateCount++;
                    $lateMinutesTotal += $summary['late_minutes'] ?? 0;
                }
                if ($summary['status'] === 'absent') {
                    $absences++;
                }
                if ($summary['is_early_leave'] === true) {
                    $earlyLeaveCount++;
                }
                $overtimeInTotal += $summary['overtime_in_minutes'] ?? 0;
                $overtimeOutTotal += $summary['overtime_out_minutes'] ?? 0;

                $cursor = $cursor->modify('+1 day');
            }

            $rows[] = [
                'employee' => $employee->getFullName(),
                'worked_hours' => round($workedHours, 2),
                'late_count' => $lateCount,
                'late_minutes' => $lateMinutesTotal,
                'absences' => $absences,
                'early_leave_count' => $earlyLeaveCount,
                'overtime_in_total' => $overtimeInTotal,
                'overtime_out_total' => $overtimeOutTotal,
            ];
        }

        return $rows;
    }

    /**
     * Résumé jour par jour pour tous les employés actifs sur une plage de
     * dates — même principe de préchargement bulk que rangeSummary()
     * (3 requêtes au total, jamais une requête par employé/jour), mais
     * retourne chaque jour individuellement au lieu d'agréger.
     *
     * @return array<int, array{date: \DateTimeImmutable, rows: array}>
     */
    public function rangeSummaryForAll(\DateTimeImmutable $start, \DateTimeImmutable $end, ?int $departmentId = null): array
    {
        $start = $start->setTime(0, 0, 0);
        $end = $end->setTime(23, 59, 59);

        $eventsByEmployeeAndDay = [];
        foreach ($this->events->findForAllEmployeesBetween($start, $end) as $event) {
            $employeeId = $event->getEmployee()->getId();
            $day = $event->getOccurredAt()->format('Y-m-d');
            $eventsByEmployeeAndDay[$employeeId][$day][] = $event;
        }

        $onLeaveByEmployeeAndDay = [];
        foreach ($this->leaves->findOverlapping($start, $end) as $leave) {
            $employeeId = $leave->getEmployee()->getId();
            $cursor = max($leave->getStartDate(), $start);
            $until = min($leave->getEndDate(), $end);
            while ($cursor <= $until) {
                $onLeaveByEmployeeAndDay[$employeeId][$cursor->format('Y-m-d')] = true;
                $cursor = $cursor->modify('+1 day');
            }
        }

        $holidayDays = array_flip(array_map(
            fn (\DateTimeImmutable $d) => $d->format('Y-m-d'),
            $this->holidays->findDatesBetween($start, $end)
        ));

        $employees = $this->activeEmployees($departmentId);

        $days = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $day = $cursor->format('Y-m-d');
            $isHoliday = isset($holidayDays[$day]);

            $rows = array_map(
                fn (Employee $e) => $this->classifyDay(
                    $e,
                    $eventsByEmployeeAndDay[$e->getId()][$day] ?? [],
                    $cursor,
                    $isHoliday,
                    $onLeaveByEmployeeAndDay[$e->getId()][$day] ?? false,
                ),
                $employees
            );

            $days[] = ['date' => $cursor, 'rows' => $rows];

            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    /** @return Employee[] employés actifs, filtrés par departmentId si fourni */
    private function activeEmployees(?int $departmentId): array
    {
        $employees = $this->employees->findActive();

        if ($departmentId === null) {
            return $employees;
        }

        return array_values(array_filter(
            $employees,
            fn (Employee $e) => $e->getDepartment()?->getId() === $departmentId
        ));
    }

    /**
     * Historique jour par jour d'un employé sur une plage de dates —
     * mêmes règles de classification que dailySummary(), mais un événement
     * bulk par requête (2 requêtes: events + leaves, + 1 pour les fériés)
     * au lieu d'une requête par jour de la plage.
     *
     * @return array<int, array>
     */
    public function employeeHistory(Employee $employee, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $start = $start->setTime(0, 0, 0);
        $end = $end->setTime(23, 59, 59);

        $eventsByDay = [];
        foreach ($this->events->findForEmployeeBetween($employee, $start, $end) as $event) {
            $eventsByDay[$event->getOccurredAt()->format('Y-m-d')][] = $event;
        }

        $onLeaveDays = [];
        foreach ($this->leaves->findOverlappingForEmployee($employee, $start, $end) as $leave) {
            $cursor = max($leave->getStartDate(), $start);
            $until = min($leave->getEndDate(), $end);
            while ($cursor <= $until) {
                $onLeaveDays[$cursor->format('Y-m-d')] = true;
                $cursor = $cursor->modify('+1 day');
            }
        }

        $holidayDays = array_flip(array_map(
            fn (\DateTimeImmutable $d) => $d->format('Y-m-d'),
            $this->holidays->findDatesBetween($start, $end)
        ));

        $rows = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $day = $cursor->format('Y-m-d');

            $rows[] = $this->classifyDay(
                $employee,
                $eventsByDay[$day] ?? [],
                $cursor,
                isset($holidayDays[$day]),
                $onLeaveDays[$day] ?? false,
            );

            $cursor = $cursor->modify('+1 day');
        }

        return $rows;
    }

    /**
     * Classifie une journée pour un employé à partir des événements déjà
     * chargés — logique pure, partagée par dailySummary() et rangeSummary()
     * pour ne jamais dupliquer les règles de retard/absence.
     *
     * @param AttendanceEvent[] $dayEvents
     */
    private function classifyDay(
        Employee $employee,
        array $dayEvents,
        \DateTimeImmutable $date,
        bool $isHoliday,
        bool $onLeave,
    ): array {
        $schedule = $employee->getWorkSchedule();

        if (empty($dayEvents)) {
            $status = $isHoliday ? 'holiday' : ($onLeave ? 'on_leave' : 'absent');

            return [
                'employee' => $employee->getFullName(),
                'date' => $date->format('Y-m-d'),
                'check_in' => null,
                'check_out' => null,
                'worked_hours' => null,
                'events_count' => 0,
                'status' => $status,
                'late_minutes' => null,
                'early_leave_minutes' => null,
                'is_early_leave' => null,
                'expected_hours' => null,
                'overtime_in_minutes' => null,
                'overtime_out_minutes' => null,
                'schedule' => $schedule?->getName(),
            ];
        }

        [$first, $last] = $this->classifyPunches($dayEvents, $schedule, $date);

        $workedHours = null;
        if ($last) {
            $diffMinutes = ($last->getTimestamp() - $first->getTimestamp()) / 60;
            $workedHours = round($diffMinutes / 60, 2);
        }

        $lateMinutes = null;
        $earlyLeaveMinutes = null;
        $overtimeInMinutes = null;
        $overtimeOutMinutes = null;
        if ($schedule) {
            [$lateMinutes, $earlyLeaveMinutes, $overtimeInMinutes, $overtimeOutMinutes] = $this->computeScheduleDeltas($schedule, $first, $last, $date);
        }

        $status = ($lateMinutes ?? 0) > 0 ? 'late' : 'present';

        $expectedHours = $workedHours !== null ? $this->expectedDailyHours($schedule) : null;
        $isEarlyLeave = $workedHours !== null ? $workedHours < $expectedHours : null;

        return [
            'employee' => $employee->getFullName(),
            'date' => $date->format('Y-m-d'),
            'check_in' => $first->format('H:i:s'),
            'check_out' => $last?->format('H:i:s'),
            'worked_hours' => $workedHours,
            'events_count' => count($dayEvents),
            'status' => $status,
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyLeaveMinutes,
            'is_early_leave' => $isEarlyLeave,
            'expected_hours' => $expectedHours,
            'overtime_in_minutes' => $overtimeInMinutes,
            'overtime_out_minutes' => $overtimeOutMinutes,
            'schedule' => $schedule?->getName(),
        ];
    }

    /**
     * Détermine l'entrée et la sortie réelles parmi les pointages du jour.
     * Sans WorkSchedule: comportement historique inchangé (1er pointage =
     * entrée, dernier = sortie) — pas de régression pour les employés non
     * configurés.
     * Avec WorkSchedule: priorité au champ attendanceStatus envoyé par la
     * pointeuse (checkIn/checkOut) quand présent — fiable, explicite. Pour
     * les pointages sans ce champ (majorité des événements de contrôle
     * d'accès bruts), classement par fenêtre horaire dérivée de
     * startTime/endTime ± checkWindowMarginMinutes, coupée au milieu de la
     * plage horaire attendue.
     *
     * @param AttendanceEvent[] $dayEvents triés chronologiquement
     * @return array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable} [checkIn, checkOut]
     */
    private function classifyPunches(array $dayEvents, ?WorkSchedule $schedule, \DateTimeImmutable $date): array
    {
        if (! $schedule) {
            $first = $dayEvents[0]->getOccurredAt();
            $last = count($dayEvents) > 1 ? end($dayEvents)->getOccurredAt() : null;

            return [$first, $last];
        }

        [$expectedStart, $expectedEnd] = $this->expectedWindow($schedule, $date);

        $margin = $schedule->getCheckWindowMarginMinutes() * 60;
        $midpoint = (int) (($expectedStart->getTimestamp() + $expectedEnd->getTimestamp()) / 2);

        $checkIn = null;
        $checkOut = null;

        foreach ($dayEvents as $event) {
            $status = $event->getAttendanceStatus();

            $isCheckIn = $status === 'checkIn';
            $isCheckOut = $status === 'checkOut';

            if (! $isCheckIn && ! $isCheckOut) {
                $timestamp = $event->getOccurredAt()->getTimestamp();
                $withinWindow = $timestamp >= $expectedStart->getTimestamp() - $margin
                    && $timestamp <= $expectedEnd->getTimestamp() + $margin;

                if ($withinWindow) {
                    $isCheckIn = $timestamp <= $midpoint;
                    $isCheckOut = ! $isCheckIn;
                }
            }

            if ($isCheckIn && $checkIn === null) {
                $checkIn = $event->getOccurredAt();
            }
            if ($isCheckOut) {
                $checkOut = $event->getOccurredAt();
            }
        }

        // Aucun pointage classé entrée (ex. tous hors fenêtre) : retombe sur
        // le tout premier pointage du jour pour ne jamais renvoyer un
        // check_in null alors qu'il y a bien eu un événement.
        $checkIn ??= $dayEvents[0]->getOccurredAt();

        return [$checkIn, $checkOut];
    }

    /**
     * Compare l'entrée/sortie réelles aux horaires attendus + tolérance.
     *
     * @return array{0: int, 1: ?int, 2: int, 3: ?int} [lateMinutes, earlyLeaveMinutes, overtimeInMinutes, overtimeOutMinutes]
     */
    private function computeScheduleDeltas(
        WorkSchedule $schedule,
        \DateTimeImmutable $checkIn,
        ?\DateTimeImmutable $checkOut,
        \DateTimeImmutable $date,
    ): array {
        [$expectedStart, $expectedEnd] = $this->expectedWindow($schedule, $date);

        $lateMinutes = max(0, (int) round(
            ($checkIn->getTimestamp() - $expectedStart->getTimestamp() - $schedule->getToleranceMinutes() * 60) / 60
        ));

        $overtimeInMinutes = max(0, (int) round(
            ($expectedStart->getTimestamp() - $checkIn->getTimestamp()) / 60
        ));

        $earlyLeaveMinutes = null;
        $overtimeOutMinutes = null;
        if ($checkOut) {
            $earlyLeaveMinutes = max(0, (int) round(
                ($expectedEnd->getTimestamp() - $checkOut->getTimestamp()) / 60
            ));
            $overtimeOutMinutes = max(0, (int) round(
                ($checkOut->getTimestamp() - $expectedEnd->getTimestamp()) / 60
            ));
        }

        return [$lateMinutes, $earlyLeaveMinutes, $overtimeInMinutes, $overtimeOutMinutes];
    }

    /**
     * Fenêtre horaire attendue pour un WorkSchedule à une date donnée —
     * gère le travail de nuit à cheval sur minuit (crossesMidnight()).
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [expectedStart, expectedEnd]
     */
    private function expectedWindow(WorkSchedule $schedule, \DateTimeImmutable $date): array
    {
        $expectedStart = $date->setTime(
            (int) $schedule->getStartTime()->format('H'),
            (int) $schedule->getStartTime()->format('i'),
        );
        $expectedEnd = $date->setTime(
            (int) $schedule->getEndTime()->format('H'),
            (int) $schedule->getEndTime()->format('i'),
        );
        if ($schedule->crossesMidnight()) {
            $expectedEnd = $expectedEnd->modify('+1 day');
        }

        return [$expectedStart, $expectedEnd];
    }

    /** Nombre d'heures attendues par jour: durée du WorkSchedule, ou 8h par défaut sans schedule assigné. */
    private function expectedDailyHours(?WorkSchedule $schedule): float
    {
        if (! $schedule) {
            return 8.0;
        }

        [$expectedStart, $expectedEnd] = $this->expectedWindow($schedule, new \DateTimeImmutable('today'));

        return ($expectedEnd->getTimestamp() - $expectedStart->getTimestamp()) / 3600;
    }
}
