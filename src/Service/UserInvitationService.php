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

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
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

    private function assignNewToken(User $user): void
    {
        $user->setResetToken(bin2hex(random_bytes(32)));
        $user->setResetTokenExpiresAt(new \DateTimeImmutable('+' . self::TOKEN_TTL_HOURS . ' hours'));
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
            ->subject('Votre accès à Présence Hikvision')
            ->html($html);

        $this->mailer->send($email);
    }
}
