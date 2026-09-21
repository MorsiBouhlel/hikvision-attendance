<?php

namespace App\Command;

use App\Repository\DeviceRepository;
use App\Service\WebhookRegistrar;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:hikvision:register-webhook',
    description: 'Configure une pointeuse pour pousser ses événements vers notre webhook',
)]
class RegisterDeviceWebhookCommand extends Command
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly WebhookRegistrar $webhookRegistrar,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('deviceId', InputArgument::REQUIRED, 'ID du device en base')
            ->addOption('base-url', null, InputOption::VALUE_OPTIONAL, 'URL publique du backend (override)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $device = $this->devices->find($input->getArgument('deviceId'));
        if (! $device) {
            $io->error('Device introuvable.');
            return Command::FAILURE;
        }

        try {
            $webhookUrl = $this->webhookRegistrar->register($device, $input->getOption('base-url'));
            $io->success("Webhook enregistré sur {$device->getName()} → {$webhookUrl}");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error("Échec: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
