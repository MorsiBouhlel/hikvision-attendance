<?php

namespace App\Service;

use App\Entity\Device;
use Doctrine\ORM\EntityManagerInterface;

class AttendanceEventSyncService
{
    public function __construct(
        private readonly HikvisionClientFactory $clientFactory,
        private readonly AttendanceEventMapper $eventMapper,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return int nombre d'événements synchronisés */
    public function sync(Device $device, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $payloads = $this->clientFactory->forDevice($device)->searchEvents($from, $to);

        foreach ($payloads as $payload) {
            $this->eventMapper->map($device, $payload);
        }

        $this->em->flush();

        return count($payloads);
    }
}
