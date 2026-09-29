<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\UserInvitationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PasswordResetController extends AbstractController
{
    #[Route('/mot-de-passe-oublie', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        UserRepository $users,
        UserInvitationService $invitations,
        LoggerInterface $logger,
    ): Response {
        if ($request->isMethod('POST')) {
            if (! $this->isCsrfTokenValid('forgot_password', (string) $request->request->get('_token'))) {
                $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
                return $this->redirectToRoute('app_forgot_password');
            }

            $user = $users->findOneBy(['email' => trim((string) $request->request->get('email'))]);
            if ($user && $user->isActive()) {
                try {
                    $invitations->sendPasswordReset($user);
                } catch (\Throwable $e) {
                    $logger->warning('Envoi e-mail de réinitialisation échoué', ['error' => $e->getMessage()]);
                }
            }

            // Même réponse que l'e-mail existe ou non, pour ne pas révéler quels comptes existent.
            $this->addFlash('success', ['key' => 'flash.password_reset_requested']);
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/forgot_password.html.twig');
    }
}
