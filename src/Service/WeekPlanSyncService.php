<?php

namespace App\Service;

use App\Entity\Device;
use App\Entity\WorkSchedule;
use Psr\Log\LoggerInterface;

class WeekPlanSyncService
{
    private const ISAPI_WEEKDAY_NAMES = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    private const ISAPI_WEEKDAY_TO_DAYOFWEEK = [
        'Monday' => 1,
        'Tuesday' => 2,
        'Wednesday' => 3,
        'Thursday' => 4,
        'Friday' => 5,
        'Saturday' => 6,
        'Sunday' => 7,
    ];

    public function __construct(
        private readonly HikvisionClientFactory $clientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Détermine le plan ISAPI (UserRightWeekPlanCfg/{planNo}) réellement assigné
     * aux employés d'un device, en lisant UserInfo.RightPlan[].planTemplateNo —
     * ce n'est PAS déductible autrement (pas forcément 1, vu 4 en production).
     * Prend le planTemplateNo le plus fréquent parmi les premiers utilisateurs
     * du device (normalement tous identiques) ; log un warning s'ils divergent.
     * Retourne null si aucun utilisateur/RightPlan exploitable n'est trouvé.
     */
    public function resolvePlanNo(Device $device): ?int
    {
        $users = $this->clientFactory->forDevice($device)->listUsers(20);

        $counts = [];
        foreach ($users as $user) {
            $planTemplateNo = $user['RightPlan'][0]['planTemplateNo'] ?? null;
            if ($planTemplateNo !== null) {
                $counts[(int) $planTemplateNo] = ($counts[(int) $planTemplateNo] ?? 0) + 1;
            }
        }

        if (empty($counts)) {
            return null;
        }

        if (count($counts) > 1) {
            $this->logger->warning('Plusieurs planTemplateNo distincts détectés sur le device, plan le plus fréquent retenu', [
                'device_id' => $device->getId(),
                'counts' => $counts,
            ]);
        }

        arsort($counts);
        return array_key_first($counts);
    }

    /**
     * $planNo: override explicite (debug/tests) — laissé null pour résoudre
     * automatiquement via resolvePlanNo() dans le cas normal.
     *
     * @return int le planNo réellement utilisé pour la synchro
     */
    public function sync(Device $device, WorkSchedule $schedule, ?int $planNo = null): int
    {
        $planNo ??= $this->resolvePlanNo($device);
        if ($planNo === null) {
            throw new \RuntimeException("Impossible de déterminer le plan ISAPI réel pour {$device->getName()} — aucun employé avec RightPlan trouvé sur ce device.");
        }

        $this->clientFactory->forDevice($device)->setWeekPlan($planNo, $this->buildPayload($schedule));

        $this->logger->info('Planning hebdomadaire synchronisé sur le device', [
            'device_id' => $device->getId(),
            'schedule_id' => $schedule->getId(),
            'plan_no' => $planNo,
        ]);

        return $planNo;
    }

    /**
     * Lit le planning ISAPI réellement en place sur un device (résout le planNo
     * comme sync()) et retourne des données prêtes à peupler un nouveau
     * WorkSchedule/WorkScheduleDay — ne persiste rien, l'appelant construit les
     * entités. Ne garde que le créneau id=1 par jour (seul utilisé par
     * buildPayload(), les 7 autres sont toujours désactivés par construction).
     *
     * @return array{planNo: int, days: array<int, array{isRestDay: bool, startTime: ?\DateTimeImmutable, endTime: ?\DateTimeImmutable}>}
     */
    public function importFromDevice(Device $device, ?int $planNo = null): array
    {
        $planNo ??= $this->resolvePlanNo($device);
        if ($planNo === null) {
            throw new \RuntimeException("Impossible de déterminer le plan ISAPI réel pour {$device->getName()} — aucun employé avec RightPlan trouvé sur ce device.");
        }

        $response = $this->clientFactory->forDevice($device)->getWeekPlan($planNo);
        $weekPlanCfg = $response['UserRightWeekPlanCfg']['WeekPlanCfg'] ?? [];

        $days = [];
        foreach ($weekPlanCfg as $entry) {
            if ((int) ($entry['id'] ?? 0) !== 1) {
                continue;
            }

            $dayOfWeek = self::ISAPI_WEEKDAY_TO_DAYOFWEEK[$entry['week']] ?? null;
            if ($dayOfWeek === null) {
                continue;
            }

            $enabled = (bool) ($entry['enable'] ?? false);
            $days[$dayOfWeek] = [
                'isRestDay' => ! $enabled,
                'startTime' => $enabled ? $this->parseIsapiTime($entry['TimeSegment']['beginTime'] ?? '00:00:00') : null,
                'endTime' => $enabled ? $this->parseIsapiTime($entry['TimeSegment']['endTime'] ?? '00:00:00') : null,
            ];
        }

        return ['planNo' => $planNo, 'days' => $days];
    }

    /**
     * Ce firmware renvoie "24:00:00" comme borne de fin de journée (vu sur les
     * plans ISAPI de ce device), que \DateTimeImmutable ne parse pas — normalisé
     * en 23:59:59, différence négligeable pour ce cas d'usage (édition/affichage).
     */
    private function parseIsapiTime(string $time): \DateTimeImmutable
    {
        if ($time === '24:00:00') {
            $time = '23:59:59';
        }

        return new \DateTimeImmutable($time);
    }

    /**
     * Format validé contre un device réel (firmware V3.3.15, DS-K1T341CMF) :
     * PUT UserRightWeekPlanCfg/{n} attend 8 créneaux par jour (TimeSegment id 1-8,
     * limite matérielle de ce modèle), pas un seul — un payload à 1 créneau/jour
     * est rejeté en 400. Seul id=1 est activé avec la fenêtre réelle, id 2-8
     * restent désactivés à 00:00:00-00:00:00, même convention que celle déjà
     * utilisée par ce device sur ses autres plans (voir GET du même endpoint).
     */
    private function buildPayload(WorkSchedule $schedule): array
    {
        $weekPlanCfg = [];
        foreach (self::ISAPI_WEEKDAY_NAMES as $dayOfWeek => $week) {
            $window = $schedule->resolvedWindowFor($dayOfWeek);
            $enabled = ! $window['isRestDay'];

            for ($id = 1; $id <= 8; $id++) {
                $weekPlanCfg[] = [
                    'week' => $week,
                    'id' => $id,
                    'enable' => $id === 1 && $enabled,
                    'TimeSegment' => [
                        'beginTime' => $id === 1 && $enabled ? ($window['startTime']?->format('H:i:s') ?? '00:00:00') : '00:00:00',
                        'endTime' => $id === 1 && $enabled ? ($window['endTime']?->format('H:i:s') ?? '00:00:00') : '00:00:00',
                    ],
                ];
            }
        }

        return ['UserRightWeekPlanCfg' => ['enable' => true, 'WeekPlanCfg' => $weekPlanCfg]];
    }
}
