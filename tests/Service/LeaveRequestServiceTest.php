<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Employee;
use App\Entity\Leave;
use App\Entity\LeaveRequest;
use App\Repository\LeaveRepository;
use App\Repository\LeaveRequestRepository;
use App\Service\LeaveBalanceService;
use App\Service\LeaveRequestService;
use App\Service\NotificationMailer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LeaveRequestServiceTest extends TestCase
{
    private LeaveBalanceService&MockObject $balances;
    private EntityManagerInterface&MockObject $em;
    private NotificationMailer&MockObject $mailer;
    private LeaveRequestService $service;

    protected function setUp(): void
    {
        $this->balances = $this->createMock(LeaveBalanceService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->mailer = $this->createMock(NotificationMailer::class);
        $this->service = new LeaveRequestService(
            $this->em,
            $this->balances,
            $this->createMock(LeaveRepository::class),
            $this->createMock(LeaveRequestRepository::class),
            $this->mailer,
        );
    }

    private function request(string $start, string $end, string $type = 'conge'): LeaveRequest
    {
        return (new LeaveRequest())
            ->setEmployee((new Employee())->setFirstName('A')->setLastName('B'))
            ->setStartDate(new \DateTimeImmutable($start))
            ->setEndDate(new \DateTimeImmutable($end))
            ->setType($type);
    }

    /** @param array<int, float> $remainingByYear */
    private function balancesFor(array $remainingByYear, int $workingDaysPerSegment): void
    {
        $this->balances->method('balance')->willReturnCallback(
            fn ($e, int $year) => ['remaining' => $remainingByYear[$year], 'pending' => 0.0]
        );
        $this->balances->method('workingDays')->willReturn($workingDaysPerSegment);
    }

    public function testWithinBalanceDoesNotWarn(): void
    {
        $this->balancesFor([2026 => 10.0], 5);

        $this->assertFalse($this->service->exceedsBalance($this->request('2026-11-02', '2026-11-06')));
    }

    public function testOverBalanceWarns(): void
    {
        $this->balancesFor([2026 => 3.0], 5);

        $this->assertTrue($this->service->exceedsBalance($this->request('2026-11-02', '2026-11-06')));
    }

    public function testRequestSpanningTwoYearsWarnsWhenTheSecondYearIsShort(): void
    {
        $this->balancesFor([2026 => 10.0, 2027 => 1.0], 2);

        $this->assertTrue($this->service->exceedsBalance($this->request('2026-12-30', '2027-01-04')));
    }

    public function testRequestSpanningTwoYearsDoesNotWarnWhenBothYearsCoverIt(): void
    {
        $this->balancesFor([2026 => 10.0, 2027 => 10.0], 2);

        $this->assertFalse($this->service->exceedsBalance($this->request('2026-12-30', '2027-01-04')));
    }

    public function testNonCongeTypesNeverWarn(): void
    {
        $this->balancesFor([2026 => 0.0], 5);

        $this->assertFalse($this->service->exceedsBalance($this->request('2026-11-02', '2026-11-06', 'maladie')));
    }

    public function testCancellingAnApprovedRequestRemovesItsLeaveAndNotifiesManagers(): void
    {
        $request = $this->request('2099-01-05', '2099-01-06')->setStatus(LeaveRequest::STATUS_APPROVED);
        $leave = new Leave();
        $request->setLeave($leave);

        $this->em->expects($this->once())->method('remove')->with($leave);
        $this->mailer->expects($this->once())->method('sendToManagers');

        $this->service->cancel($request);

        $this->assertSame(LeaveRequest::STATUS_CANCELLED, $request->getStatus());
        $this->assertNull($request->getLeave());
    }

    public function testCancellingAPendingRequestSendsNothing(): void
    {
        $request = $this->request('2099-01-05', '2099-01-06');

        $this->em->expects($this->never())->method('remove');
        $this->mailer->expects($this->never())->method('sendToManagers');

        $this->service->cancel($request);

        $this->assertSame(LeaveRequest::STATUS_CANCELLED, $request->getStatus());
    }

    public function testApprovedRequestIsCancellableOnlyBeforeItStarts(): void
    {
        $future = $this->request('2099-01-05', '2099-01-06')->setStatus(LeaveRequest::STATUS_APPROVED);
        $started = $this->request('2000-01-05', '2000-01-06')->setStatus(LeaveRequest::STATUS_APPROVED);
        $rejected = $this->request('2099-01-05', '2099-01-06')->setStatus(LeaveRequest::STATUS_REJECTED);

        $this->assertTrue($future->isCancellable());
        $this->assertFalse($started->isCancellable());
        $this->assertFalse($rejected->isCancellable());
    }
}
