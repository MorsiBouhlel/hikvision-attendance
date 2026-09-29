<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Employee;
use App\Entity\PermissionRequest;
use App\Entity\RemoteWorkRequest;
use App\Repository\HrSettingsRepository;
use App\Repository\PermissionRequestRepository;
use App\Repository\RemoteWorkRequestRepository;

/**
 * Quotas mensuels de télétravail (jours ouvrés) et d'autorisations (heures). Valeur globale
 * dans HrSettings, surchargeable par employé. Le dépassement ne bloque jamais : il sert
 * uniquement à avertir l'employé et à signaler la demande au manager.
 * La consommation compte les demandes acceptées ET en attente.
 */
class QuotaService
{
    public function __construct(
        private readonly HrSettingsRepository $settings,
        private readonly RemoteWorkRequestRepository $remoteRequests,
        private readonly PermissionRequestRepository $permissionRequests,
        private readonly LeaveBalanceService $balances,
    ) {
    }

    public function remoteWorkQuota(Employee $employee): int
    {
        return $employee->getRemoteWorkQuotaOverride() ?? $this->settings->getOrCreate()->getRemoteWorkDaysPerMonth();
    }

    public function permissionQuota(Employee $employee): float
    {
        return $employee->getPermissionQuotaOverride() ?? $this->settings->getOrCreate()->getPermissionHoursPerMonth();
    }

    /** Jours ouvrés de télétravail (acceptés + en attente) sur le mois de $month, hors demande $excludeId. */
    public function remoteWorkUsed(Employee $employee, \DateTimeImmutable $month, ?int $excludeId = null): int
    {
        [$start, $end] = $this->monthBounds($month);
        $days = 0;

        foreach ($this->remoteRequests->findActiveOverlapping($employee, $start, $end) as $r) {
            if ($r->getId() !== null && $r->getId() === $excludeId) {
                continue;
            }
            $days += $this->balances->workingDays($employee, max($r->getStartDate(), $start), min($r->getEndDate(), $end));
        }

        return $days;
    }

    /** Heures d'autorisation (acceptées + en attente) sur le mois de $month, hors demande $excludeId. */
    public function permissionUsed(Employee $employee, \DateTimeImmutable $month, ?int $excludeId = null): float
    {
        [$start, $end] = $this->monthBounds($month);
        $hours = 0.0;

        foreach ($this->permissionRequests->findActiveBetween($employee, $start, $end) as $r) {
            if ($r->getId() === null || $r->getId() !== $excludeId) {
                $hours += $r->getHours();
            }
        }

        return round($hours, 2);
    }

    /** La demande (nouvelle ou déjà enregistrée) fait-elle dépasser le quota d'un des mois qu'elle touche ? */
    public function exceedsRemoteWorkQuota(RemoteWorkRequest $request): bool
    {
        $employee = $request->getEmployee();
        $quota = $this->remoteWorkQuota($employee);

        for ($month = $request->getStartDate()->modify('first day of this month'); $month <= $request->getEndDate(); $month = $month->modify('+1 month')) {
            [$start, $end] = $this->monthBounds($month);
            $own = $this->balances->workingDays($employee, max($request->getStartDate(), $start), min($request->getEndDate(), $end));

            if ($this->remoteWorkUsed($employee, $month, $request->getId()) + $own > $quota) {
                return true;
            }
        }

        return false;
    }

    public function exceedsPermissionQuota(PermissionRequest $request): bool
    {
        $employee = $request->getEmployee();

        return $this->permissionUsed($employee, $request->getDate(), $request->getId()) + $request->getHours() > $this->permissionQuota($employee);
    }

    /**
     * @return array{remote: array{used: int, quota: int}, permission: array{used: float, quota: float}}
     */
    public function monthSummary(Employee $employee, \DateTimeImmutable $month): array
    {
        return [
            'remote' => ['used' => $this->remoteWorkUsed($employee, $month), 'quota' => $this->remoteWorkQuota($employee)],
            'permission' => ['used' => $this->permissionUsed($employee, $month), 'quota' => $this->permissionQuota($employee)],
        ];
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function monthBounds(\DateTimeImmutable $month): array
    {
        $start = $month->modify('first day of this month')->setTime(0, 0, 0);

        return [$start, $start->modify('last day of this month')];
    }
}
