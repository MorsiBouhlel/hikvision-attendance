<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Employee;
use App\Entity\Leave;
use App\Repository\HolidayRepository;
use App\Repository\LeaveAdjustmentRepository;
use App\Repository\LeaveRepository;
use App\Repository\LeaveRequestRepository;

/**
 * Solde de congés annuel : acquisition mensuelle (annualLeaveDays / 12 par
 * mois écoulé, prorata à partir de hireDate si embauché dans l'année) +
 * ajustements manuels + report N-1 − congés de type "conge" pris.
 */
class LeaveBalanceService
{
    public function __construct(
        private readonly LeaveRepository $leaves,
        private readonly LeaveRequestRepository $requests,
        private readonly LeaveAdjustmentRepository $adjustments,
        private readonly HolidayRepository $holidays,
        private readonly int $carryOverFirstSourceYear,
    ) {
    }

    /**
     * Jours ouvrés entre deux dates incluses : hors jours de repos de l'horaire
     * de l'employé (ou samedi/dimanche s'il n'en a pas) et hors jours fériés.
     *
     * @param \DateTimeImmutable[]|null $holidayDates fournis par l'appelant pour éviter une requête par employé
     */
    public function workingDays(Employee $employee, \DateTimeImmutable $start, \DateTimeImmutable $end, ?array $holidayDates = null): int
    {
        $holidayDates ??= $this->holidays->findDatesBetween($start, $end);
        $holidaySet = array_flip(array_map(fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $holidayDates));
        $schedule = $employee->getWorkSchedule();

        $count = 0;
        for ($day = $start->setTime(0, 0, 0); $day <= $end; $day = $day->modify('+1 day')) {
            $dow = (int) $day->format('N');
            $isRest = $schedule ? $schedule->resolvedWindowFor($dow)['isRestDay'] : $dow >= 6;
            if (! $isRest && ! isset($holidaySet[$day->format('Y-m-d')])) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return array{accrued: float, carried_over: float, adjustments: float, taken: float, pending: float, remaining: float}
     */
    public function balance(Employee $employee, int $year): array
    {
        return $this->balances([$employee], $year)[$employee->getId()];
    }

    /**
     * Version en lot : 4 requêtes (8 si un report N-1 s'applique) quel que soit le nombre d'employés.
     *
     * Report N-1 automatique et illimité : les jours de l'année précédente non pris
     * (acquis + ajustements − pris, sans son propre report — pas de cascade sur plusieurs années)
     * s'ajoutent au 1er janvier, jamais en négatif. Il n'existe que si l'année précédente est
     * >= carryOverFirstSourceYear, pour ne pas créditer un historique antérieur à l'application.
     *
     * @param Employee[] $employees
     * @return array<int, array{accrued: float, carried_over: float, adjustments: float, taken: float, pending: float, remaining: float}>
     */
    public function balances(array $employees, int $year): array
    {
        $current = $this->yearFigures($employees, $year);
        $previous = $year - 1 >= $this->carryOverFirstSourceYear ? $this->yearFigures($employees, $year - 1) : [];

        $result = [];
        foreach ($employees as $e) {
            $id = $e->getId();
            $carried = isset($previous[$id])
                ? round(max(0.0, $previous[$id]['accrued'] + $previous[$id]['adjustments'] - $previous[$id]['taken']), 2)
                : 0.0;
            $result[$id] = $current[$id] + [
                'carried_over' => $carried,
                'remaining' => round($current[$id]['accrued'] + $carried + $current[$id]['adjustments'] - $current[$id]['taken'], 2),
            ];
        }

        return $result;
    }

    /**
     * @param Employee[] $employees
     * @return array<int, array{accrued: float, adjustments: float, taken: float, pending: float}>
     */
    private function yearFigures(array $employees, int $year): array
    {
        $yearStart = new \DateTimeImmutable("$year-01-01");
        $yearEnd = new \DateTimeImmutable("$year-12-31");

        $holidayDates = $this->holidays->findDatesBetween($yearStart, $yearEnd);
        $adjustmentSums = $this->adjustments->sumsByEmployee($year);

        $takenByEmployee = $this->sumWorkingDays(
            array_filter($this->leaves->findOverlapping($yearStart, $yearEnd), fn (Leave $l) => $l->getType() === 'conge'),
            $yearStart, $yearEnd, $holidayDates,
        );
        $pendingByEmployee = $this->sumWorkingDays(
            array_filter($this->requests->findPendingBetween($yearStart, $yearEnd), fn ($r) => $r->getType() === 'conge'),
            $yearStart, $yearEnd, $holidayDates,
        );

        $figures = [];
        foreach ($employees as $e) {
            $id = $e->getId();
            $figures[$id] = [
                'accrued' => $this->accrued($e, $year),
                'adjustments' => $adjustmentSums[$id] ?? 0.0,
                'taken' => $takenByEmployee[$id] ?? 0.0,
                'pending' => $pendingByEmployee[$id] ?? 0.0,
            ];
        }

        return $figures;
    }

    private function accrued(Employee $employee, int $year): float
    {
        $today = new \DateTimeImmutable();
        $currentYear = (int) $today->format('Y');

        if ($year > $currentYear) {
            return 0.0;
        }

        $lastMonth = $year < $currentYear ? 12 : (int) $today->format('n');
        $firstMonth = 1;
        $hire = $employee->getHireDate();
        if ($hire && (int) $hire->format('Y') === $year) {
            $firstMonth = (int) $hire->format('n');
        } elseif ($hire && (int) $hire->format('Y') > $year) {
            return 0.0;
        }

        return round(max(0, $lastMonth - $firstMonth + 1) * $employee->getAnnualLeaveDays() / 12, 2);
    }

    /**
     * @param iterable<Leave|\App\Entity\LeaveRequest> $periods
     * @param \DateTimeImmutable[] $holidayDates
     * @return array<int, float> jours ouvrés par employee id, bornés à [$from, $to]
     */
    private function sumWorkingDays(iterable $periods, \DateTimeImmutable $from, \DateTimeImmutable $to, array $holidayDates): array
    {
        $sums = [];
        foreach ($periods as $p) {
            $start = max($p->getStartDate(), $from);
            $end = min($p->getEndDate(), $to);
            $id = $p->getEmployee()->getId();
            $sums[$id] = ($sums[$id] ?? 0.0) + $this->workingDays($p->getEmployee(), $start, $end, $holidayDates);
        }

        return $sums;
    }
}
