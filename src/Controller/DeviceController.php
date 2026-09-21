<?php

namespace App\Controller;

use App\Entity\Device;
use App\Form\DeviceType;
use App\Repository\DeviceRepository;
use App\Service\AttendanceEventSyncService;
use App\Service\EmployeeSyncService;
use App\Service\WebhookRegistrar;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/devices', name: 'device_')]
class DeviceController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(DeviceRepository $devices): Response
    {
        return $this->render('device/index.html.twig', [
            'devices' => $devices->findAll(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $device = new Device();
        $form = $this->createForm(DeviceType::class, $device, ['is_edit' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $device->setAdminPassword($form->get('adminPassword')->getData());

            $em->persist($device);
            $em->flush();

            $this->addFlash('success', "Device {$device->getName()} créé.");
            return $this->redirectToRoute('device_index');
        }

        return $this->render('device/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Device $device, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(DeviceType::class, $device, ['is_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = $form->get('adminPassword')->getData();
            if ($newPassword) {
                $device->setAdminPassword($newPassword);
            }

            $em->flush();

            $this->addFlash('success', "Device {$device->getName()} mis à jour.");
            return $this->redirectToRoute('device_index');
        }

        return $this->render('device/edit.html.twig', [
            'form' => $form,
            'device' => $device,
        ]);
    }

    #[Route('/{id}/register-webhook', name: 'register_webhook', methods: ['POST'])]
    public function registerWebhook(Device $device, Request $request, WebhookRegistrar $registrar): Response
    {
        if (! $this->isCsrfTokenValid('device_action_' . $device->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('device_index');
        }

        try {
            $webhookUrl = $registrar->register($device);
            $this->addFlash('success', "Webhook enregistré sur {$device->getName()} → {$webhookUrl}");
        } catch (\Throwable $e) {
            $this->addFlash('error', "Échec de l'enregistrement du webhook pour {$device->getName()} : {$e->getMessage()}");
        }

        return $this->redirectToRoute('device_index');
    }

    #[Route('/{id}/sync-employees', name: 'sync_employees_preview', methods: ['GET'])]
    public function syncEmployeesPreview(Device $device, EmployeeSyncService $employeeSync): Response
    {
        try {
            $unlinked = $employeeSync->previewUnlinked($device);
        } catch (\Throwable $e) {
            $this->addFlash('error', "Impossible de contacter {$device->getName()} : {$e->getMessage()}");
            return $this->redirectToRoute('device_index');
        }

        return $this->render('device/sync_preview.html.twig', [
            'device' => $device,
            'unlinked' => $unlinked,
        ]);
    }

    #[Route('/{id}/sync-employees', name: 'sync_employees_confirm', methods: ['POST'])]
    public function syncEmployeesConfirm(Device $device, Request $request, EmployeeSyncService $employeeSync): Response
    {
        if (! $this->isCsrfTokenValid('device_sync_' . $device->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('device_index');
        }

        $faceUrls = $request->request->all('selected_face_url');

        $selected = [];
        foreach ($request->request->all('selected') as $employeeNo => $name) {
            $selected[] = [
                'employeeNo' => (string) $employeeNo,
                'name' => (string) $name,
                'faceURL' => $faceUrls[$employeeNo] ?? null,
            ];
        }

        $created = $employeeSync->linkSelected($device, $selected);

        $this->addFlash('success', count($created) . ' employé(s) créé(s) et lié(s) à ' . $device->getName() . '.');

        return $this->redirectToRoute('device_index');
    }

    #[Route('/{id}/sync-events', name: 'sync_events', methods: ['POST'])]
    public function syncEvents(Device $device, Request $request, AttendanceEventSyncService $eventSync): Response
    {
        if (! $this->isCsrfTokenValid('device_sync_events_' . $device->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('device_index');
        }

        $fromInput = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $request->request->get('from'));
        $from = ($fromInput ?: new \DateTimeImmutable('-30 days'))->setTime(0, 0, 0);

        $toInput = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $request->request->get('to'));
        $to = ($toInput ?: new \DateTimeImmutable())->setTime(23, 59, 59);

        try {
            $count = $eventSync->sync($device, $from, $to);
            $this->addFlash('success', "{$count} événement(s) synchronisé(s) depuis {$device->getName()}.");
        } catch (\Throwable $e) {
            $this->addFlash('error', "Échec de la synchronisation pour {$device->getName()} : {$e->getMessage()}");
        }

        return $this->redirectToRoute('device_index');
    }
}
