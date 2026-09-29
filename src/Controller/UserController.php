<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Service\UserInvitationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/users', name: 'user_')]
class UserController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(UserRepository $users): Response
    {
        return $this->render('user/index.html.twig', [
            'users' => $users->findAll(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, UserInvitationService $invitations): Response
    {
        $user = new User();
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setRoles([$form->get('role')->getData()]);
            $invitations->invite($user);

            $this->addFlash('success', ['key' => 'flash.user_invited', 'params' => ['%email%' => $user->getEmail()]]);
            return $this->redirectToRoute('user_index');
        }

        return $this->render('user/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/resend-invitation', name: 'resend_invitation', methods: ['POST'])]
    public function resendInvitation(User $user, Request $request, UserInvitationService $invitations): Response
    {
        if (! $this->isCsrfTokenValid('user_resend_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
            return $this->redirectToRoute('user_index');
        }

        $invitations->resend($user);

        $this->addFlash('success', ['key' => 'flash.invitation_resent', 'params' => ['%email%' => $user->getEmail()]]);
        return $this->redirectToRoute('user_index');
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if (! $this->isCsrfTokenValid('user_toggle_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
            return $this->redirectToRoute('user_index');
        }

        if ($user === $this->getUser()) {
            $this->addFlash('error', ['key' => 'flash.cannot_deactivate_self']);
            return $this->redirectToRoute('user_index');
        }

        $user->setIsActive(! $user->isActive());
        $em->flush();

        $this->addFlash('success', [
            'key' => $user->isActive() ? 'flash.user_activated' : 'flash.user_deactivated',
            'params' => ['%email%' => $user->getEmail()],
        ]);
        return $this->redirectToRoute('user_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if (! $this->isCsrfTokenValid('user_delete_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
            return $this->redirectToRoute('user_index');
        }

        if ($user === $this->getUser()) {
            $this->addFlash('error', ['key' => 'flash.cannot_delete_self']);
            return $this->redirectToRoute('user_index');
        }

        $em->remove($user);
        $em->flush();

        $this->addFlash('success', ['key' => 'flash.user_deleted', 'params' => ['%email%' => $user->getEmail()]]);
        return $this->redirectToRoute('user_index');
    }
}
