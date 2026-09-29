<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Twig\Environment;

class UserInvitationService
{
    private const TOKEN_TTL_HOURS = 24;
    private const RESET_TOKEN_TTL_HOURS = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly NotificationMailer $notifications,
        private readonly string $appBaseUrl, // injecté via services.yaml, ex: %env(APP_BASE_URL)%
        private readonly string $mailerFromAddress,
        private readonly string $mailerFromName,
    ) {
    }

    /**
     * Génère un token d'invitation à durée limitée (24h) et envoie le mail
     * contenant le lien de définition du mot de passe. Le mot de passe
     * stocké est un hash aléatoire inutilisable tant que l'utilisateur n'a
     * pas défini le sien via ce lien.
     */
    public function invite(User $user): void
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
        $this->assignNewToken($user);

        $this->em->persist($user);
        $this->em->flush();

        $this->sendInvitationEmail($user);
    }

    public function resend(User $user): void
    {
        $this->assignNewToken($user);
        $this->em->flush();

        $this->sendInvitationEmail($user);
    }

    /** Mot de passe oublié : le mot de passe actuel reste valable jusqu'à ce que le lien soit utilisé. */
    public function sendPasswordReset(User $user): void
    {
        $this->assignNewToken($user, self::RESET_TOKEN_TTL_HOURS);
        $this->em->flush();

        $link = rtrim($this->appBaseUrl, '/') . "/definir-mot-de-passe/{$user->getResetToken()}";

        $this->notifications->send(
            $user->getEmail(),
            'Réinitialisation de votre mot de passe',
            sprintf(
                "Une réinitialisation de mot de passe a été demandée pour %s.\n\nCe lien est valable %d heures. Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail : votre mot de passe actuel reste inchangé.",
                $user->getEmail(),
                self::RESET_TOKEN_TTL_HOURS,
            ),
            'Définir un nouveau mot de passe',
            $link,
        );
    }

    private function assignNewToken(User $user, int $ttlHours = self::TOKEN_TTL_HOURS): void
    {
        $user->setResetToken(bin2hex(random_bytes(32)));
        $user->setResetTokenExpiresAt(new \DateTimeImmutable("+$ttlHours hours"));
    }

    private function sendInvitationEmail(User $user): void
    {
        $baseUrl = rtrim($this->appBaseUrl, '/');
        $link = "{$baseUrl}/definir-mot-de-passe/{$user->getResetToken()}";

        $html = $this->twig->render('emails/user_invitation.html.twig', [
            'email' => $user->getEmail(),
            'link' => $link,
        ]);

        $email = (new Email())
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to($user->getEmail())
            ->subject('Votre accès à Softy RH')
            ->text("Un compte a été créé pour vous ({$user->getEmail()}). Définissez votre mot de passe (lien valable 24 heures) : {$link}")
            ->html($html);

        $this->mailer->send($email);
    }
}
