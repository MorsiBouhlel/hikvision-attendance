<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Employee;
use App\Entity\Leave;
use App\Entity\LeaveRequest;
use App\Repository\HolidayRepository;
use App\Repository\LeaveAdjustmentRepository;
use App\Repository\LeaveRepository;
use App\Repository\LeaveRequestRepository;
use App\Service\LeaveBalanceService;
use PHPUnit\Framework\TestCase;

class LeaveBalanceServiceTest extends TestCase
{
    /** @var \DateTimeImmutable[] */
    private array $holidays = [];
    /** @var Leave[] */
    private array $leaves = [];
    /** @var LeaveRequest[] */
    private array $pendingRequests = [];
    /** @var array<int, array<int, float>> année => employee id => total */
    private array $adjustments = [];

    private function service(int $carryOverFirstSourceYear = 2020): LeaveBalanceService
    {
        $holidayRepo = $this->createMock(HolidayRepository::class);
        $holidayRepo->method('findDatesBetween')->willReturnCallback(
            fn (\DateTimeImmutable $s, \DateTimeImmutable $e) => array_values(array_filter($this->holidays, fn ($d) => $d >= $s && $d <= $e))
        );

        $leaveRepo = $this->createMock(LeaveRepository::class);
        $leaveRepo->method('findOverlapping')->willReturnCallback(
            fn (\DateTimeImmutable $s, \DateTimeImmutable $e) => array_values(array_filter($this->leaves, fn (Leave $l) => $l->getStartDate() <= $e && $l->getEndDate() >= $s))
        );

        $requestRepo = $this->createMock(LeaveRequestRepository::class);
        $requestRepo->method('findPendingBetween')->willReturnCallback(
            fn (\DateTimeImmutable $s, \DateTimeImmutable $e) => array_values(array_filter($this->pendingRequests, fn (LeaveRequest $r) => $r->getStartDate() <= $e && $r->getEndDate() >= $s))
        );

        $adjustmentRepo = $this->createMock(LeaveAdjustmentRepository::class);
        $adjustmentRepo->method('sumsByEmployee')->willReturnCallback(fn (int $year) => $this->adjustments[$year] ?? []);

        return new LeaveBalanceService($leaveRepo, $requestRepo, $adjustmentRepo, $holidayRepo, $carryOverFirstSourceYear);
    }

    private function employee(int $id = 1, float $annualDays = 24.0, ?string $hireDate = null): Employee
    {
        $e = (new Employee())->setFirstName('A')->setLastName('B')->setAnnualLeaveDays($annualDays);
        $e->setHireDate($hireDate ? new \DateTimeImmutable($hireDate) : null);
        (new \ReflectionProperty(Employee::class, 'id'))->setValue($e, $id);

        return $e;
    }

    private function leave(Employee $e, string $start, string $end, string $type = 'conge'): Leave
    {
        return (new Leave())->setEmployee($e)->setStartDate(new \DateTimeImmutable($start))->setEndDate(new \DateTimeImmutable($end))->setType($type);
    }

    public function testWorkingDaysExcludeWeekendsAndHolidays(): void
    {
        $this->holidays = [new \DateTimeImmutable('2020-03-04')]; // mercredi

        // lundi 2 → vendredi 6 mars + lundi 9 = 6 jours, moins le férié
        $days = $this->service()->workingDays($this->employee(), new \DateTimeImmutable('2020-03-02'), new \DateTimeImmutable('2020-03-09'));

        $this->assertSame(5, $days);
    }

    public function testFullPastYearAccruesTheWholeAnnualEntitlement(): void
    {
        $balance = $this->service()->balance($this->employee(annualDays: 24.0), 2020);

        $this->assertSame(24.0, $balance['accrued']);
        $this->assertSame(24.0, $balance['remaining']);
    }

    public function testHireDateInTheYearProratesAccrual(): void
    {
        // embauché en avril : avril → décembre = 9 mois sur 12
        $balance = $this->service()->balance($this->employee(annualDays: 24.0, hireDate: '2020-04-15'), 2020);

        $this->assertSame(18.0, $balance['accrued']);
    }

    public function testYearBeforeHireAccruesNothing(): void
    {
        $balance = $this->service()->balance($this->employee(hireDate: '2021-01-10'), 2020);

        $this->assertSame(0.0, $balance['accrued']);
    }

    public function testFutureYearAccruesNothing(): void
    {
        $balance = $this->service()->balance($this->employee(), (int) date('Y') + 1);

        $this->assertSame(0.0, $balance['accrued']);
    }

    public function testOnlyCongeLeavesAreDeducted(): void
    {
        $e = $this->employee();
        $this->leaves = [
            $this->leave($e, '2020-03-02', '2020-03-04'),             // 3 j de congé
            $this->leave($e, '2020-03-09', '2020-03-13', 'maladie'),  // ignoré
        ];

        $balance = $this->service()->balance($e, 2020);

        $this->assertSame(3.0, $balance['taken']);
        $this->assertSame(21.0, $balance['remaining']);
    }

    public function testLeaveSpanningTwoYearsIsClippedToEachYear(): void
    {
        $e = $this->employee();
        // mer 30 déc 2020 → ven 1er jan 2021 (férié non déclaré) : 30, 31 dans 2020 ; 1er jan (vendredi) dans 2021
        $this->leaves = [$this->leave($e, '2020-12-30', '2021-01-01')];

        $service = $this->service();

        $this->assertSame(2.0, $service->balance($e, 2020)['taken']);
        $this->assertSame(1.0, $service->balance($e, 2021)['taken']);
    }

    public function testPendingRequestsAreCountedSeparatelyFromRemaining(): void
    {
        $e = $this->employee();
        $this->pendingRequests = [(new LeaveRequest())->setEmployee($e)->setStartDate(new \DateTimeImmutable('2020-03-02'))->setEndDate(new \DateTimeImmutable('2020-03-03'))];

        $balance = $this->service()->balance($e, 2020);

        $this->assertSame(2.0, $balance['pending']);
        $this->assertSame(24.0, $balance['remaining']);
    }

    public function testAdjustmentsAreAdded(): void
    {
        $this->adjustments = [2020 => [1 => -2.5]];

        $balance = $this->service()->balance($this->employee(), 2020);

        $this->assertSame(-2.5, $balance['adjustments']);
        $this->assertSame(21.5, $balance['remaining']);
    }

    public function testUnusedDaysAreCarriedOverFromPreviousYear(): void
    {
        $e = $this->employee(annualDays: 24.0);
        $this->leaves = [$this->leave($e, '2020-03-02', '2020-03-06')]; // 5 j pris en 2020 → 19 restants

        $balance = $this->service()->balance($e, 2021);

        $this->assertSame(19.0, $balance['carried_over']);
        $this->assertSame(24.0 + 19.0, $balance['remaining']);
    }

    public function testNegativePreviousBalanceIsNotCarriedOver(): void
    {
        $e = $this->employee(annualDays: 24.0);
        $this->adjustments = [2020 => [1 => -30.0]];

        $balance = $this->service()->balance($e, 2021);

        $this->assertSame(0.0, $balance['carried_over']);
        $this->assertSame(24.0, $balance['remaining']);
    }

    public function testCarryOverDoesNotCascadeAcrossSeveralYears(): void
    {
        $e = $this->employee(annualDays: 24.0);

        // 2020 : 24 acquis, 0 pris ; 2021 : 24 acquis + 24 reportés. Report vers 2022 = 24 (2021 propre), pas 48.
        $balance = $this->service()->balance($e, 2022);

        $this->assertSame(24.0, $balance['carried_over']);
    }

    public function testNoCarryOverFromYearsBeforeTheConfiguredFirstSourceYear(): void
    {
        $balance = $this->service(carryOverFirstSourceYear: 2021)->balance($this->employee(), 2021);

        $this->assertSame(0.0, $balance['carried_over']);
    }

    public function testBatchBalancesAreIndexedByEmployeeId(): void
    {
        $a = $this->employee(1);
        $b = $this->employee(2, 12.0);

        $balances = $this->service()->balances([$a, $b], 2020);

        $this->assertSame(24.0, $balances[1]['accrued']);
        $this->assertSame(12.0, $balances[2]['accrued']);
    }
}
