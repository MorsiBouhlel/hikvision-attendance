<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LocaleController extends AbstractController
{
    private const AVAILABLE = ['fr', 'en'];

    #[Route('/locale/{locale}', name: 'locale_switch', methods: ['POST'])]
    public function switch(string $locale, Request $request, EntityManagerInterface $em): Response
    {
        if (! $this->isCsrfTokenValid('locale_switch', (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
            return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('dashboard'));
        }

        if (in_array($locale, self::AVAILABLE, true)) {
            $user = $this->getUser();
            if ($user instanceof User) {
                $user->setLocale($locale);
                $em->flush();
                $request->getSession()->set('_locale', $locale);
            }
        }

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('dashboard'));
    }
}
