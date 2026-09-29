<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Leave;
use App\Entity\LeaveRequest;
use App\Entity\User;
use App\Repository\LeaveRepository;
use App\Repository\LeaveRequestRepository;
use Doctrine\ORM\EntityManagerInterface;

class LeaveRequestService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LeaveBalanceService $balances,
        private readonly LeaveRepository $leaves,
        private readonly LeaveRequestRepository $requests,
        private readonly NotificationMailer $mailer,
    ) {
    }

    /**
     * Valide une demande avant enregistrement.
     *
     * @return string|null clé de traduction (flash.*) de l'erreur, ou null si la demande est recevable
     */
    public function validate(LeaveRequest $request): ?string
    {
        $start = $request->getStartDate();
        $end = $request->getEndDate();
        $employee = $request->getEmployee();

        if ($end < $start) {
            return 'flash.leave_end_before_start';
        }

        $days = $this->balances->workingDays($employee, $start, $end);
        if ($days === 0) {
            return 'flash.leave_request_no_working_day';
        }

        if ($this->leaves->findOverlappingForEmployee($employee, $start, $end)
            || $this->requests->findPendingOverlapping($employee, $start, $end)) {
            return 'flash.leave_request_overlap';
        }

        return null;
    }

    /** Non bloquant : une demande au-delà du solde est acceptée, l'employé et le manager en sont juste avertis. */
    public function exceedsBalance(LeaveRequest $request): bool
    {
        if ($request->getType() !== 'conge') {
            return false;
        }

        $employee = $request->getEmployee();
        $firstYear = (int) $request->getStartDate()->format('Y');
        $lastYear = (int) $request->getEndDate()->format('Y');

        // Une demande à cheval sur deux années consomme le solde de chacune : on compare année par année.
        for ($year = $firstYear; $year <= $lastYear; ++$year) {
            $from = max($request->getStartDate(), new \DateTimeImmutable("$year-01-01"));
            $to = min($request->getEndDate(), new \DateTimeImmutable("$year-12-31"));
            $balance = $this->balances->balance($employee, $year);

            if ($this->balances->workingDays($employee, $from, $to) > $balance['remaining'] - $balance['pending']) {
                return true;
            }
        }

        return false;
    }

    public function submit(LeaveRequest $request): void
    {
        $this->em->persist($request);
        $this->em->flush();

        $this->mailer->sendToManagers(
            'Nouvelle demande de congé',
            sprintf(
                "%s a demandé un congé du %s au %s.%s",
                $request->getEmployee()->getFullName(),
                $request->getStartDate()->format('d/m/Y'),
                $request->getEndDate()->format('d/m/Y'),
                $request->getReason() ? "\n\nMotif : " . $request->getReason() : '',
            ),
            'Traiter la demande',
            '/demandes-conges',
            'warning',
        );
    }

    public function approve(LeaveRequest $request, User $decider, ?string $comment): void
    {
        $leave = (new Leave())
            ->setEmployee($request->getEmployee())
            ->setStartDate($request->getStartDate())
            ->setEndDate($request->getEndDate())
            ->setType($request->getType())
            ->setReason($request->getReason());
        $this->em->persist($leave);

        $request->setLeave($leave);
        $this->decide($request, LeaveRequest::STATUS_APPROVED, $decider, $comment);
    }

    public function reject(LeaveRequest $request, User $decider, ?string $comment): void
    {
        $this->decide($request, LeaveRequest::STATUS_REJECTED, $decider, $comment);
    }

    public function cancel(LeaveRequest $request): void
    {
        $wasApproved = $request->getStatus() === LeaveRequest::STATUS_APPROVED;

        // Un congé accepté a déjà généré un Leave lu par AttendanceService : il doit disparaître avec l'annulation.
        if ($wasApproved && $leave = $request->getLeave()) {
            $request->setLeave(null);
            $this->em->remove($leave);
        }

        $request->setStatus(LeaveRequest::STATUS_CANCELLED);
        $this->em->flush();

        if ($wasApproved) {
            $this->mailer->sendToManagers('Congé accepté annulé', sprintf(
                "%s a annulé son congé du %s au %s (précédemment accepté).",
                $request->getEmployee()->getFullName(),
                $request->getStartDate()->format('d/m/Y'),
                $request->getEndDate()->format('d/m/Y'),
            ), 'Voir le calendrier', '/calendrier');
        }
    }

    private function decide(LeaveRequest $request, string $status, User $decider, ?string $comment): void
    {
        $request->setStatus($status)
            ->setDecidedBy($decider)
            ->setDecidedAt(new \DateTimeImmutable())
            ->setDecisionComment($comment !== '' ? $comment : null);
        $this->em->flush();

        $verdict = $status === LeaveRequest::STATUS_APPROVED ? 'acceptée' : 'refusée';
        $body = sprintf(
            "Votre demande de congé du %s au %s a été %s.%s",
            $request->getStartDate()->format('d/m/Y'),
            $request->getEndDate()->format('d/m/Y'),
            $verdict,
            $request->getDecisionComment() ? "\n\nCommentaire : " . $request->getDecisionComment() : '',
        );
        $this->mailer->sendToEmployee(
            $request->getEmployee(),
            "Demande de congé $verdict",
            $body,
            'Voir mon espace',
            '/mon-espace',
            $status === LeaveRequest::STATUS_APPROVED ? 'success' : 'danger',
        );
    }
}
