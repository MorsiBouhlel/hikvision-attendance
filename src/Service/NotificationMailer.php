<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Employee;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * E-mails des workflows RH : HTML stylé (emails/notification.html.twig) + alternative texte.
 * Un échec d'envoi est loggé, jamais propagé.
 */
class NotificationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
        private readonly Environment $twig,
        private readonly string $appBaseUrl,
        private readonly string $mailerFromAddress,
        private readonly string $mailerFromName,
    ) {
    }

    /**
     * @param string      $body       paragraphes séparés par une ligne vide
     * @param string|null $actionPath chemin de l'app (ex. "/mon-espace") ou URL absolue, cible du bouton
     * @param string      $tone       info|success|danger|warning — couleur d'accent
     */
    public function send(string $to, string $subject, string $body, ?string $actionLabel = null, ?string $actionPath = null, string $tone = 'info'): void
    {
        $actionUrl = $actionPath === null ? null : (str_starts_with($actionPath, 'http') ? $actionPath : rtrim($this->appBaseUrl, '/') . $actionPath);

        try {
            $html = $this->twig->render('emails/notification.html.twig', [
                'subject' => $subject,
                'paragraphs' => preg_split('/\R{2,}/', trim($body)),
                'actionLabel' => $actionLabel,
                'actionUrl' => $actionUrl,
                'tone' => $tone,
            ]);

            $this->mailer->send(
                (new Email())
                    ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
                    ->to($to)
                    ->subject($subject)
                    ->text($actionUrl ? "$body\n\n$actionLabel : $actionUrl" : $body)
                    ->html($html)
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Envoi e-mail échoué', ['to' => $to, 'error' => $e->getMessage()]);
        }
    }

    public function sendToManagers(string $subject, string $body, ?string $actionLabel = null, ?string $actionPath = null, string $tone = 'info'): void
    {
        foreach ($this->users->findAll() as $user) {
            $roles = $user->getRoles();
            if ($user->isActive() && (in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true))) {
                $this->send($user->getEmail(), $subject, $body, $actionLabel, $actionPath, $tone);
            }
        }
    }

    public function sendToEmployee(Employee $employee, string $subject, string $body, ?string $actionLabel = null, ?string $actionPath = null, string $tone = 'info'): void
    {
        if ($user = $this->users->findByEmployee($employee)) {
            $this->send($user->getEmail(), $subject, $body, $actionLabel, $actionPath, $tone);
        }
    }
}
