<?php

namespace App\Controller;

use App\Repository\DeviceRepository;
use App\Service\AttendanceAlertService;
use App\Service\AttendanceEventMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

class HikvisionWebhookController extends AbstractController
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly AttendanceEventMapper $eventMapper,
        private readonly AttendanceAlertService $alerts,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/hikvision/webhook/{token}', name: 'hikvision_webhook', methods: ['POST'])]
    public function handle(Request $request, string $token): Response
    {
        $this->logger->info('Webhook Hikvision: requête reçue', [
            'token' => substr($token, 0, 8) . '…',
            'content_type' => $request->headers->get('Content-Type'),
            'content_length' => $request->headers->get('Content-Length'),
            'ip' => $request->getClientIp(),
        ]);

        $device = $this->devices->findByWebhookToken($token);

        if (! $device) {
            $this->logger->warning('Webhook Hikvision: token inconnu', ['token' => substr($token, 0, 8) . '…']);
            return $this->json(['error' => 'unknown device'], 404);
        }

        $device->setLastSeenAt(new \DateTimeImmutable());

        $payload = $this->extractPayload($request);

        if (! $payload) {
            $this->logger->warning('Webhook Hikvision: payload vide ou illisible', [
                'device_id' => $device->getId(),
                'raw_body' => substr($request->getContent(), 0, 2000),
            ]);
            $this->em->flush();
            return $this->ackResponse();
        }

        $this->logger->info('Webhook Hikvision: payload reçu', [
            'device_id' => $device->getId(),
            'payload' => $payload,
        ]);

        [$event, $wasNew] = $this->eventMapper->map($device, $payload);
        $this->em->flush();

        $this->logger->info('Webhook Hikvision: événement enregistré', [
            'event_id' => $event->getId(),
            'employee_id' => $event->getEmployee()?->getId(),
            'employee_no' => $event->getEmployeeNo(),
            'occurred_at' => $event->getOccurredAt()->format('c'),
            'was_new' => $wasNew,
        ]);

        // Alerte retard uniquement pour un événement réellement nouveau daté
        // d'aujourd'hui — jamais pour un rattrapage historique ou une mise
        // à jour d'un événement déjà connu (idempotence par serialNo).
        $isToday = $event->getOccurredAt()->format('Y-m-d') === (new \DateTimeImmutable('today'))->format('Y-m-d');
        if ($wasNew && $isToday) {
            $this->alerts->checkLate($event);
        }

        return $this->ackResponse();
    }

    /**
     * Réponse ISAPI standard attendue par la pointeuse en confirmation d'un
     * événement reçu — un simple 204 vide n'était pas reconnu comme un
     * succès et déclenchait un retry en boucle serrée côté device.
     */
    private function ackResponse(): Response
    {
        return $this->json([
            'statusCode' => 1,
            'statusString' => 'OK',
            'subStatusCode' => 'ok',
        ]);
    }

    private function extractPayload(Request $request): ?array
    {
        $contentType = $request->headers->get('Content-Type', '');

        // Cas 1: JSON direct
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($request->getContent(), true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        // Cas 2: multipart avec un champ contenant le JSON/XML de l'event (+ photo jointe éventuelle)
        $raw = $request->request->get('event_log') ?? $request->request->get('Event') ?? null;

        if ($raw) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }

            $xml = simplexml_load_string($raw);
            if ($xml !== false) {
                return json_decode(json_encode($xml), true);
            }
        }

        // Cas 3: fallback générique sur le body brut
        $body = $request->getContent();
        if ($body) {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }
}
