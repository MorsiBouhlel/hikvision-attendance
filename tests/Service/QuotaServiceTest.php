<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Employee;
use App\Entity\HrSettings;
use App\Entity\PermissionRequest;
use App\Entity\RemoteWorkRequest;
use App\Repository\HrSettingsRepository;
use App\Repository\PermissionRequestRepository;
use App\Repository\RemoteWorkRequestRepository;
use App\Service\LeaveBalanceService;
use App\Service\QuotaService;
use PHPUnit\Framework\TestCase;

class QuotaServiceTest extends TestCase
{
    /** @var RemoteWorkRequest[] */
    private array $remote = [];
    /** @var PermissionRequest[] */
    private array $permissions = [];

    private function service(int $remoteQuota = 4, float $permissionQuota = 4.0): QuotaService
    {
        $settings = (new HrSettings())->setRemoteWorkDaysPerMonth($remoteQuota)->setPermissionHoursPerMonth($permissionQuota);
        $settingsRepo = $this->createMock(HrSettingsRepository::class);
        $settingsRepo->method('getOrCreate')->willReturn($settings);

        $remoteRepo = $this->createMock(RemoteWorkRequestRepository::class);
        $remoteRepo->method('findActiveOverlapping')->willReturnCallback(
            fn (Employee $e, \DateTimeImmutable $s, \DateTimeImmutable $en) => array_values(array_filter(
                $this->remote,
                fn (RemoteWorkRequest $r) => $r->getStartDate() <= $en && $r->getEndDate() >= $s
            ))
        );

        $permissionRepo = $this->createMock(PermissionRequestRepository::class);
        $permissionRepo->method('findActiveBetween')->willReturnCallback(
            fn (Employee $e, \DateTimeImmutable $s, \DateTimeImmutable $en) => array_values(array_filter(
                $this->permissions,
                fn (PermissionRequest $r) => $r->getDate() >= $s && $r->getDate() <= $en
            ))
        );

        // Jours ouvrés simplifiés : lundi–vendredi, sans férié (la vraie logique est testée dans LeaveBalanceServiceTest).
        $balances = $this->createMock(LeaveBalanceService::class);
        $balances->method('workingDays')->willReturnCallback(function (Employee $e, \DateTimeImmutable $s, \DateTimeImmutable $en): int {
            $n = 0;
            for ($d = $s; $d <= $en; $d = $d->modify('+1 day')) {
                $n += (int) $d->format('N') < 6 ? 1 : 0;
            }

            return $n;
        });

        return new QuotaService($settingsRepo, $remoteRepo, $permissionRepo, $balances);
    }

    private function employee(): Employee
    {
        return (new Employee())->setFirstName('A')->setLastName('B');
    }

    private function remoteRequest(Employee $e, string $start, string $end): RemoteWorkRequest
    {
        return (new RemoteWorkRequest())->setEmployee($e)->setStartDate(new \DateTimeImmutable($start))->setEndDate(new \DateTimeImmutable($end));
    }

    private function permissionRequest(Employee $e, string $date, string $from, string $to): PermissionRequest
    {
        return (new PermissionRequest())->setEmployee($e)->setDate(new \DateTimeImmutable($date))
            ->setStartTime(new \DateTimeImmutable($from))->setEndTime(new \DateTimeImmutable($to));
    }

    public function testGlobalQuotaIsUsedUnlessTheEmployeeHasAnOverride(): void
    {
        $service = $this->service(remoteQuota: 8, permissionQuota: 6.0);
        $e = $this->employee();

        $this->assertSame(8, $service->remoteWorkQuota($e));
        $this->assertSame(6.0, $service->permissionQuota($e));

        $e->setRemoteWorkQuotaOverride(2)->setPermissionQuotaOverride(1.5);

        $this->assertSame(2, $service->remoteWorkQuota($e));
        $this->assertSame(1.5, $service->permissionQuota($e));
    }

    public function testAnOverrideOfZeroIsRespectedAndNotTreatedAsMissing(): void
    {
        $e = $this->employee()->setRemoteWorkQuotaOverride(0);

        $this->assertSame(0, $this->service(remoteQuota: 8)->remoteWorkQuota($e));
    }

    public function testRemoteWorkWithinQuotaDoesNotExceed(): void
    {
        $e = $this->employee();
        $this->remote = [$this->remoteRequest($e, '2026-11-02', '2026-11-04')]; // 3 j déjà pris

        $this->assertFalse($this->service(remoteQuota: 4)->exceedsRemoteWorkQuota($this->remoteRequest($e, '2026-11-09', '2026-11-09'))); // +1 = 4
    }

    public function testRemoteWorkOverQuotaExceeds(): void
    {
        $e = $this->employee();
        $this->remote = [$this->remoteRequest($e, '2026-11-02', '2026-11-04')];

        $this->assertTrue($this->service(remoteQuota: 4)->exceedsRemoteWorkQuota($this->remoteRequest($e, '2026-11-09', '2026-11-10'))); // +2 = 5
    }

    public function testWeekendsAreNotCountedAsRemoteWorkDays(): void
    {
        $e = $this->employee();

        // samedi 7 → dimanche 8 nov : 0 jour ouvré
        $this->assertFalse($this->service(remoteQuota: 0)->exceedsRemoteWorkQuota($this->remoteRequest($e, '2026-11-07', '2026-11-08')));
    }

    public function testRemoteRequestSpanningTwoMonthsIsCheckedMonthByMonth(): void
    {
        $e = $this->employee();
        // 2 j déjà pris en décembre ; la demande couvre 30–31 oct (2 j) puis 1–2 déc (2 j) → décembre : 2 + 2 = 4
        $this->remote = [$this->remoteRequest($e, '2026-12-14', '2026-12-15')];
        $request = $this->remoteRequest($e, '2026-10-30', '2026-12-02');

        $this->assertTrue($this->service(remoteQuota: 3)->exceedsRemoteWorkQuota($request));
    }

    public function testASavedRequestDoesNotCountAgainstItself(): void
    {
        $e = $this->employee();
        $saved = $this->remoteRequest($e, '2026-11-02', '2026-11-05'); // 4 j
        (new \ReflectionProperty(RemoteWorkRequest::class, 'id'))->setValue($saved, 7);
        $this->remote = [$saved];

        $this->assertFalse($this->service(remoteQuota: 4)->exceedsRemoteWorkQuota($saved));
        $this->assertSame(0, $this->service()->remoteWorkUsed($e, new \DateTimeImmutable('2026-11-15'), 7));
    }

    public function testPermissionHoursAreSummedOverTheMonth(): void
    {
        $e = $this->employee();
        $this->permissions = [$this->permissionRequest($e, '2026-11-03', '09:00', '11:00')]; // 2 h

        $service = $this->service(permissionQuota: 4.0);

        $this->assertSame(2.0, $service->permissionUsed($e, new \DateTimeImmutable('2026-11-20')));
        $this->assertFalse($service->exceedsPermissionQuota($this->permissionRequest($e, '2026-11-10', '14:00', '16:00'))); // 4 h pile
        $this->assertTrue($service->exceedsPermissionQuota($this->permissionRequest($e, '2026-11-10', '14:00', '16:30'))); // 4,5 h
    }

    public function testPermissionHoursHandleHalfHours(): void
    {
        $this->assertSame(1.5, $this->permissionRequest($this->employee(), '2026-11-03', '09:00', '10:30')->getHours());
    }
}
