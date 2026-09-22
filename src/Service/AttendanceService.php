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
            $dayEvents = $eventsByDay[$day] ?? [];

            $row = $this->classifyDay(
                $employee,
                $dayEvents,
                $cursor,
                isset($holidayDays[$day]),
                $onLeaveDays[$day] ?? false,
            );

            usort($dayEvents, fn (AttendanceEvent $a, AttendanceEvent $b) => $a->getOccurredAt() <=> $b->getOccurredAt());
            $row['punches'] = $dayEvents;

            $rows[] = $row;

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
            $isRestDay = $schedule && $this->expectedWindow($schedule, $date) === null;
            $status = $isHoliday ? 'holiday' : ($onLeave ? 'on_leave' : ($isRestDay ? 'rest_day' : 'absent'));

            return [
                'employee' => $employee->getFullName(),
                'employee_id' => $employee->getId(),
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

        // Une sortie (classifyPunches(), via la marge de badgeage) avant
        // l'heure de fin planifiée est un départ anticipé — décision
        // utilisateur du 2026-09-23, sans tolérance ici (contrairement au
        // retard): tout écart compte, même 1 minute avant endTime.
        if ($schedule && $last !== null) {
            $window = $this->expectedWindow($schedule, $date);
            if ($window !== null) {
                [, $expectedEnd] = $window;
                if ($last < $expectedEnd) {
                    $status = 'early_leave_pending';
                }
            }
        }

        $expectedHours = $workedHours !== null ? $this->expectedDailyHours($schedule, $date) : null;
        $isEarlyLeave = $workedHours !== null ? $workedHours < $expectedHours : null;

        return [
            'employee' => $employee->getFullName(),
            'employee_id' => $employee->getId(),
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
     * Détermine l'entrée et la sortie réelles parmi les pointages du jour,
     * indépendamment du champ attendanceStatus envoyé par la pointeuse (ce
     * champ n'est plus utilisé ici — décision utilisateur du 2026-09-23: la
     * pointeuse a parfois marqué plusieurs pointages consécutifs "checkIn"
     * à quelques minutes d'écart, rendant ce champ inexploitable pour
     * détecter un vrai changement d'état).
     * Règle: le 1er pointage du jour = entrée. Chaque pointage suivant met à
     * jour la sortie dès qu'il est séparé du pointage précédent par au moins
     * checkWindowMarginMinutes (le "délai minimum entre deux pointages pour
     * compter comme un changement d'état") — pas d'alternance entrée/sortie,
     * une fois en état "sortie" tout nouveau pointage (même rapproché)
     * continue de repousser la sortie à sa propre heure. Sans WorkSchedule:
     * comportement historique inchangé (1er pointage = entrée, dernier =
     * sortie, sans notion de marge).
     *
     * @param AttendanceEvent[] $dayEvents triés chronologiquement
     * @return array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable} [checkIn, checkOut]
     */
    private function classifyPunches(array $dayEvents, ?WorkSchedule $schedule, \DateTimeImmutable $date): array
    {
        $first = $dayEvents[0]->getOccurredAt();

        if (! $schedule) {
            $last = count($dayEvents) > 1 ? end($dayEvents)->getOccurredAt() : null;

            return [$first, $last];
        }

        $margin = $schedule->getCheckWindowMarginMinutes() * 60;

        $checkIn = $first;
        $checkOut = null;
        $reference = $first;
        $inCheckOutState = false;

        foreach (array_slice($dayEvents, 1) as $event) {
            $occurredAt = $event->getOccurredAt();

            if ($inCheckOutState || $occurredAt->getTimestamp() - $reference->getTimestamp() >= $margin) {
                $checkOut = $occurredAt;
                $inCheckOutState = true;
            }

            $reference = $occurredAt;
        }

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
        $window = $this->expectedWindow($schedule, $date);
        if ($window === null) {
            return [0, null, 0, null];
        }

        [$expectedStart, $expectedEnd] = $window;

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
     * résolue par jour de semaine via WorkSchedule::resolvedWindowFor()
     * (planning hebdomadaire : chaque jour peut avoir son propre horaire ou
     * être marqué repos). Gère le travail de nuit à cheval sur minuit,
     * calculé par jour (crossesMidnight() n'est plus fiable globalement
     * puisque l'horaire peut varier selon le jour).
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null null si le jour est marqué repos ou n'a pas d'horaire défini
     */
    private function expectedWindow(WorkSchedule $schedule, \DateTimeImmutable $date): ?array
    {
        $config = $schedule->resolvedWindowFor((int) $date->format('N'));

        if ($config['isRestDay'] || $config['startTime'] === null || $config['endTime'] === null) {
            return null;
        }

        $expectedStart = $date->setTime(
            (int) $config['startTime']->format('H'),
            (int) $config['startTime']->format('i'),
        );
        $expectedEnd = $date->setTime(
            (int) $config['endTime']->format('H'),
            (int) $config['endTime']->format('i'),
        );
        if ($expectedEnd < $expectedStart) {
            $expectedEnd = $expectedEnd->modify('+1 day');
        }

        return [$expectedStart, $expectedEnd];
    }

    /** Nombre d'heures attendues pour $date: durée de la fenêtre du jour, 0h si jour de repos, ou 8h par défaut sans schedule assigné. */
    private function expectedDailyHours(?WorkSchedule $schedule, \DateTimeImmutable $date): float
    {
        if (! $schedule) {
            return 8.0;
        }

        $window = $this->expectedWindow($schedule, $date);
        if ($window === null) {
            return 0.0;
        }

        [$expectedStart, $expectedEnd] = $window;

        return ($expectedEnd->getTimestamp() - $expectedStart->getTimestamp()) / 3600;
    }
}
