<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\SetPasswordType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class SetPasswordController extends AbstractController
{
    #[Route('/definir-mot-de-passe/{token}', name: 'user_set_password', methods: ['GET', 'POST'])]
    public function __invoke(
        string $token,
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        $user = $users->findOneBy(['resetToken' => $token]);

        if (! $user instanceof User || ! $this->isTokenValid($user)) {
            return $this->render('security/set_password_invalid.html.twig', [], new Response(status: 410));
        }

        $form = $this->createForm(SetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $user->setResetToken(null);
            $user->setResetTokenExpiresAt(null);
            $em->flush();

            $this->addFlash('success', ['key' => 'flash.password_set']);
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/set_password.html.twig', [
            'form' => $form,
        ]);
    }

    private function isTokenValid(User $user): bool
    {
        return $user->getResetTokenExpiresAt() !== null
            && $user->getResetTokenExpiresAt() > new \DateTimeImmutable();
    }
}
