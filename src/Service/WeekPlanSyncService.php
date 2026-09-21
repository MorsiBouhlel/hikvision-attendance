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

    public function __construct(
        private readonly HikvisionClientFactory $clientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * $planNo identifie le plan hebdomadaire ISAPI (UserRightWeekPlanCfg/{planNo}) —
     * ce n'est PAS forcément 1: sur ce device, les employés sont rattachés via
     * UserInfo.RightPlan[].planTemplateNo à un plan précis (vu: 4), pousser sur
     * le mauvais planNo n'a aucun effet réel puisque personne n'y est assigné.
     * Vérifier UserInfo.RightPlan côté device (ou UserRightPlanTemplate/{n}) avant
     * de synchroniser pour cibler le bon plan.
     */
    public function sync(Device $device, WorkSchedule $schedule, int $planNo): void
    {
        $this->clientFactory->forDevice($device)->setWeekPlan($planNo, $this->buildPayload($schedule));

        $this->logger->info('Planning hebdomadaire synchronisé sur le device', [
            'device_id' => $device->getId(),
            'schedule_id' => $schedule->getId(),
            'plan_no' => $planNo,
        ]);
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
