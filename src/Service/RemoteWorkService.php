<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RemoteWorkRequest;
use App\Entity\User;
use App\Repository\RemoteWorkRequestRepository;
use Doctrine\ORM\EntityManagerInterface;

class RemoteWorkService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RemoteWorkRequestRepository $requests,
        private readonly NotificationMailer $mailer,
    ) {
    }

    /** @return string|null clé de traduction (flash.*) de l'erreur, ou null si la demande est recevable */
    public function validate(RemoteWorkRequest $request): ?string
    {
        if ($request->getEndDate() < $request->getStartDate()) {
            return 'flash.leave_end_before_start';
        }

        if ($this->requests->findActiveOverlapping($request->getEmployee(), $request->getStartDate(), $request->getEndDate())) {
            return 'flash.remote_work_overlap';
        }

        return null;
    }

    public function submit(RemoteWorkRequest $request): void
    {
        $this->em->persist($request);
        $this->em->flush();

        $this->mailer->sendToManagers(
            'Nouvelle demande de télétravail',
            sprintf(
                "%s a demandé du télétravail du %s au %s.%s",
                $request->getEmployee()->getFullName(),
                $request->getStartDate()->format('d/m/Y'),
                $request->getEndDate()->format('d/m/Y'),
                $request->getReason() ? "\n\nMotif : " . $request->getReason() : '',
            ),
            'Traiter la demande',
            '/demandes-teletravail',
            'warning',
        );
    }

    public function approve(RemoteWorkRequest $request, User $decider, ?string $comment): void
    {
        $this->decide($request, RemoteWorkRequest::STATUS_APPROVED, $decider, $comment);
    }

    public function reject(RemoteWorkRequest $request, User $decider, ?string $comment): void
    {
        $this->decide($request, RemoteWorkRequest::STATUS_REJECTED, $decider, $comment);
    }

    public function cancel(RemoteWorkRequest $request): void
    {
        $wasApproved = $request->getStatus() === RemoteWorkRequest::STATUS_APPROVED;
        $request->setStatus(RemoteWorkRequest::STATUS_CANCELLED);
        $this->em->flush();

        if ($wasApproved) {
            $this->mailer->sendToManagers('Télétravail accepté annulé', sprintf(
                "%s a annulé son télétravail du %s au %s (précédemment accepté).",
                $request->getEmployee()->getFullName(),
                $request->getStartDate()->format('d/m/Y'),
                $request->getEndDate()->format('d/m/Y'),
            ), 'Voir le calendrier', '/calendrier');
        }
    }

    private function decide(RemoteWorkRequest $request, string $status, User $decider, ?string $comment): void
    {
        $request->setStatus($status)
            ->setDecidedBy($decider)
            ->setDecidedAt(new \DateTimeImmutable())
            ->setDecisionComment($comment !== '' ? $comment : null);
        $this->em->flush();

        $verdict = $status === RemoteWorkRequest::STATUS_APPROVED ? 'acceptée' : 'refusée';
        $this->mailer->sendToEmployee($request->getEmployee(), "Demande de télétravail $verdict", sprintf(
            "Votre demande de télétravail du %s au %s a été %s.%s",
            $request->getStartDate()->format('d/m/Y'),
            $request->getEndDate()->format('d/m/Y'),
            $verdict,
            $request->getDecisionComment() ? "\n\nCommentaire : " . $request->getDecisionComment() : '',
        ), 'Voir mon espace', '/mon-espace', $status === RemoteWorkRequest::STATUS_APPROVED ? 'success' : 'danger');
    }
}
