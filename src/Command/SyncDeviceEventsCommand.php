<?php

namespace App\Command;

use App\Repository\DeviceRepository;
use App\Service\AttendanceEventSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:hikvision:sync-events',
    description: "Rapatrie l'historique de pointages déjà stocké sur une pointeuse (avant l'enregistrement du webhook, ou après une coupure réseau)",
)]
class SyncDeviceEventsCommand extends Command
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly AttendanceEventSyncService $eventSync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('deviceId', InputArgument::REQUIRED, 'ID du device en base')
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Date de début (YYYY-MM-DD), défaut: il y a 30 jours')
            ->addOption('to', null, InputOption::VALUE_OPTIONAL, 'Date de fin (YYYY-MM-DD), défaut: aujourd\'hui');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $device = $this->devices->find($input->getArgument('deviceId'));
        if (! $device) {
            $io->error('Device introuvable.');
            return Command::FAILURE;
        }

        $from = $input->getOption('from')
            ? new \DateTimeImmutable($input->getOption('from'))
            : (new \DateTimeImmutable('-30 days'))->setTime(0, 0, 0);

        $to = $input->getOption('to')
            ? (new \DateTimeImmutable($input->getOption('to')))->setTime(23, 59, 59)
            : new \DateTimeImmutable();

        try {
            $count = $this->eventSync->sync($device, $from, $to);
            $io->success("{$count} événement(s) synchronisé(s) depuis {$device->getName()} ({$from->format('d/m/Y')} → {$to->format('d/m/Y')}).");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error("Échec: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
