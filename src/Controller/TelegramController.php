<?php

namespace App\Controller;

use App\Entity\TelegramRecipient;
use App\Form\TelegramRecipientType;
use App\Repository\AlertSettingsRepository;
use App\Repository\TelegramRecipientRepository;
use App\Service\AttendanceAlertService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/telegram', name: 'telegram_')]
class TelegramController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(TelegramRecipientRepository $recipients, AlertSettingsRepository $settings): Response
    {
        $form = $this->createForm(TelegramRecipientType::class, new TelegramRecipient());

        return $this->render('telegram/index.html.twig', [
            'recipients' => $recipients->findAll(),
            'enabled' => $settings->isEnabled(),
            'form' => $form,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $recipient = new TelegramRecipient();
        $form = $this->createForm(TelegramRecipientType::class, $recipient);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($recipient);
            $em->flush();

            $this->addFlash('success', "Destinataire {$recipient->getLabel()} ajouté.");
        } else {
            $this->addFlash('error', 'Formulaire invalide — vérifie le nom et le chat ID.');
        }

        return $this->redirectToRoute('telegram_index');
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(TelegramRecipient $recipient, Request $request, EntityManagerInterface $em): Response
    {
        if (! $this->isCsrfTokenValid('telegram_toggle_' . $recipient->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('telegram_index');
        }

        $recipient->setIsActive(! $recipient->isActive());
        $em->flush();

        $this->addFlash('success', "{$recipient->getLabel()} " . ($recipient->isActive() ? 'activé' : 'désactivé') . '.');
        return $this->redirectToRoute('telegram_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(TelegramRecipient $recipient, Request $request, EntityManagerInterface $em): Response
    {
        if (! $this->isCsrfTokenValid('telegram_delete_' . $recipient->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('telegram_index');
        }

        $em->remove($recipient);
        $em->flush();

        $this->addFlash('success', "Destinataire {$recipient->getLabel()} supprimé.");
        return $this->redirectToRoute('telegram_index');
    }

    #[Route('/toggle-alerts', name: 'toggle_alerts', methods: ['POST'])]
    public function toggleAlerts(Request $request, AlertSettingsRepository $settingsRepo, EntityManagerInterface $em): Response
    {
        if (! $this->isCsrfTokenValid('telegram_toggle_alerts', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('telegram_index');
        }

        $settings = $settingsRepo->getOrCreate();
        $settings->setEnabled(! $settings->isEnabled());
        $em->flush();

        $this->addFlash('success', 'Alertes ' . ($settings->isEnabled() ? 'activées' : 'désactivées') . '.');
        return $this->redirectToRoute('telegram_index');
    }

    #[Route('/test', name: 'test', methods: ['POST'])]
    public function test(Request $request, AttendanceAlertService $alerts): Response
    {
        if (! $this->isCsrfTokenValid('telegram_test', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('telegram_index');
        }

        $result = $alerts->sendTest();

        if ($result['success'] === 0 && $result['failed'] === 0) {
            $this->addFlash('error', 'Aucun destinataire actif — ajoute-en un avant de tester.');
        } else {
            $this->addFlash('success', "{$result['success']} message(s) envoyé(s), {$result['failed']} échec(s).");
        }

        return $this->redirectToRoute('telegram_index');
    }
}
