<?php

namespace App\Service;

use App\Entity\AttendanceEvent;
use App\Entity\Device;
use App\Repository\AttendanceEventRepository;
use App\Repository\DeviceEmployeeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mappe un payload Hikvision (webhook push ou AcsEvent/Search pull) vers un
 * AttendanceEvent. Les deux formats partagent globalement les mêmes clés
 * (employeeNoString, time/dateTime, major/minor, currentVerifyMode, serialNo)
 * — noms exacts dépendants du firmware, voir raw_payload en base pour ajuster.
 */
class AttendanceEventMapper
{
    public function __construct(
        private readonly DeviceEmployeeRepository $deviceEmployees,
        private readonly AttendanceEventRepository $events,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array{0: AttendanceEvent, 1: bool} [événement, était-il nouveau] */
    public function map(Device $device, array $payload): array
    {
        // Certains firmwares imbriquent tous les champs pertinents sous
        // AccessControllerEvent au lieu de les mettre à la racine du payload
        // (constaté sur des événements réels de rattrapage historique) —
        // on retombe sur ce sous-objet si la clé racine est absente.
        $ace = $payload['AccessControllerEvent'] ?? [];

        $employeeNo = $payload['employeeNoString'] ?? $payload['employeeNo']
            ?? $ace['employeeNoString'] ?? $ace['employeeNo'] ?? null;
        $serialNo = (string) ($payload['serialNo'] ?? $ace['serialNo'] ?? (($payload['dateTime'] ?? $payload['time'] ?? 'x') . '-' . ($employeeNo ?? 'unknown')));
        $verifyMode = $payload['currentVerifyMode'] ?? $payload['verifyMode'] ?? $ace['currentVerifyMode'] ?? null;
        $attendanceStatus = $payload['attendanceStatus'] ?? $ace['attendanceStatus'] ?? null;
        $eventType = $payload['eventType'] ?? null;
        $minor = match (true) {
            isset($payload['minor']) => (int) $payload['minor'],
            isset($ace['minor']) => (int) $ace['minor'],
            isset($ace['subEventType']) => (int) $ace['subEventType'],
            default => null,
        };
        $occurredAtRaw = $payload['dateTime'] ?? $payload['time'] ?? null;

        $occurredAt = $occurredAtRaw
            ? new \DateTimeImmutable($occurredAtRaw)
            : new \DateTimeImmutable();

        $existing = $this->events->findOneBy(['device' => $device, 'serialNo' => $serialNo]);
        $wasNew = $existing === null;
        $event = $existing ?? new AttendanceEvent();

        $employeeLink = null;
        if ($employeeNo) {
            $employeeLink = $this->deviceEmployees->findByDeviceAndEmployeeNo($device, $employeeNo);
        }

        $event->setDevice($device);
        $event->setEmployee($employeeLink?->getEmployee());
        $event->setEmployeeNo($employeeNo);
        $event->setSerialNo($serialNo);
        $event->setVerifyMode($verifyMode);
        $event->setAttendanceStatus($attendanceStatus);
        $event->setEventType($eventType);
        $event->setMinor($minor);
        $event->setSuccess(true); // affine avec les codes minor d'échec connus si besoin
        $event->setOccurredAt($occurredAt);
        $event->setRawPayload($payload);

        $this->em->persist($event);

        return [$event, $wasNew];
    }
}
