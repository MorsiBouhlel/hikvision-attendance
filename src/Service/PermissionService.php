<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PermissionRequest;
use App\Entity\User;
use App\Repository\PermissionRequestRepository;
use Doctrine\ORM\EntityManagerInterface;

class PermissionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PermissionRequestRepository $requests,
        private readonly NotificationMailer $mailer,
    ) {
    }

    /** @return string|null clé de traduction (flash.*) de l'erreur, ou null si la demande est recevable */
    public function validate(PermissionRequest $request): ?string
    {
        if ($request->getEndTime() <= $request->getStartTime()) {
            return 'flash.permission_end_before_start';
        }

        foreach ($this->requests->findActiveBetween($request->getEmployee(), $request->getDate(), $request->getDate()) as $other) {
            if ($other->getStartTime() < $request->getEndTime() && $other->getEndTime() > $request->getStartTime()) {
                return 'flash.permission_overlap';
            }
        }

        return null;
    }

    public function submit(PermissionRequest $request): void
    {
        $this->em->persist($request);
        $this->em->flush();

        $this->mailer->sendToManagers(
            'Nouvelle demande d\'autorisation',
            sprintf(
                "%s a demandé une autorisation le %s de %s à %s (%s h).%s",
                $request->getEmployee()->getFullName(),
                $request->getDate()->format('d/m/Y'),
                $request->getStartTime()->format('H:i'),
                $request->getEndTime()->format('H:i'),
                $request->getHours(),
                $request->getReason() ? "\n\nMotif : " . $request->getReason() : '',
            ),
            'Traiter la demande',
            '/demandes-autorisations',
            'warning',
        );
    }

    public function approve(PermissionRequest $request, User $decider, ?string $comment): void
    {
        $this->decide($request, PermissionRequest::STATUS_APPROVED, $decider, $comment);
    }

    public function reject(PermissionRequest $request, User $decider, ?string $comment): void
    {
        $this->decide($request, PermissionRequest::STATUS_REJECTED, $decider, $comment);
    }

    public function cancel(PermissionRequest $request): void
    {
        $wasApproved = $request->getStatus() === PermissionRequest::STATUS_APPROVED;
        $request->setStatus(PermissionRequest::STATUS_CANCELLED);
        $this->em->flush();

        if ($wasApproved) {
            $this->mailer->sendToManagers('Autorisation acceptée annulée', sprintf(
                "%s a annulé son autorisation du %s de %s à %s (précédemment acceptée).",
                $request->getEmployee()->getFullName(),
                $request->getDate()->format('d/m/Y'),
                $request->getStartTime()->format('H:i'),
                $request->getEndTime()->format('H:i'),
            ), 'Voir les demandes', '/demandes-autorisations');
        }
    }

    private function decide(PermissionRequest $request, string $status, User $decider, ?string $comment): void
    {
        $request->setStatus($status)
            ->setDecidedBy($decider)
            ->setDecidedAt(new \DateTimeImmutable())
            ->setDecisionComment($comment !== '' ? $comment : null);
        $this->em->flush();

        $verdict = $status === PermissionRequest::STATUS_APPROVED ? 'acceptée' : 'refusée';
        $this->mailer->sendToEmployee($request->getEmployee(), "Demande d'autorisation $verdict", sprintf(
            "Votre demande d'autorisation du %s de %s à %s a été %s.%s",
            $request->getDate()->format('d/m/Y'),
            $request->getStartTime()->format('H:i'),
            $request->getEndTime()->format('H:i'),
            $verdict,
            $request->getDecisionComment() ? "\n\nCommentaire : " . $request->getDecisionComment() : '',
        ), 'Voir mon espace', '/mon-espace', $status === PermissionRequest::STATUS_APPROVED ? 'success' : 'danger');
    }
}
