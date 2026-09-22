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

            $this->addFlash('success', ['key' => 'flash.recipient_added', 'params' => ['%name%' => $recipient->getLabel()]]);
        } else {
            $this->addFlash('error', ['key' => 'flash.invalid_recipient_form']);
        }

        return $this->redirectToRoute('telegram_index');
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(TelegramRecipient $recipient, Request $request, EntityManagerInterface $em): Response
    {
        if (! $this->isCsrfTokenValid('telegram_toggle_' . $recipient->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
            return $this->redirectToRoute('telegram_index');
        }

        $recipient->setIsActive(! $recipient->isActive());
        $em->flush();

        $this->addFlash('success', [
            'key' => $recipient->isActive() ? 'flash.recipient_activated' : 'flash.recipient_deactivated',
            'params' => ['%name%' => $recipient->getLabel()],
        ]);
        return $this->redirectToRoute('telegram_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(TelegramRecipient $recipient, Request $request, EntityManagerInterface $em): Response
    {
        if (! $this->isCsrfTokenValid('telegram_delete_' . $recipient->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
            return $this->redirectToRoute('telegram_index');
        }

        $em->remove($recipient);
        $em->flush();

        $this->addFlash('success', ['key' => 'flash.recipient_deleted', 'params' => ['%name%' => $recipient->getLabel()]]);
        return $this->redirectToRoute('telegram_index');
    }

    #[Route('/toggle-alerts', name: 'toggle_alerts', methods: ['POST'])]
    public function toggleAlerts(Request $request, AlertSettingsRepository $settingsRepo, EntityManagerInterface $em): Response
    {
        if (! $this->isCsrfTokenValid('telegram_toggle_alerts', (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
            return $this->redirectToRoute('telegram_index');
        }

        $settings = $settingsRepo->getOrCreate();
        $settings->setEnabled(! $settings->isEnabled());
        $em->flush();

        $this->addFlash('success', ['key' => $settings->isEnabled() ? 'flash.alerts_enabled' : 'flash.alerts_disabled']);
        return $this->redirectToRoute('telegram_index');
    }

    #[Route('/test', name: 'test', methods: ['POST'])]
    public function test(Request $request, AttendanceAlertService $alerts): Response
    {
        if (! $this->isCsrfTokenValid('telegram_test', (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
            return $this->redirectToRoute('telegram_index');
        }

        $result = $alerts->sendTest();

        if ($result['success'] === 0 && $result['failed'] === 0) {
            $this->addFlash('error', ['key' => 'flash.no_active_recipient']);
        } else {
            $this->addFlash('success', ['key' => 'flash.test_sent', 'params' => ['%success%' => $result['success'], '%failed%' => $result['failed']]]);
        }

        return $this->redirectToRoute('telegram_index');
    }
}
