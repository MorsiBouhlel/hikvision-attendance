<?php

namespace App\Command;

use App\Repository\DeviceRepository;
use App\Service\EmployeeSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:hikvision:sync-employees',
    description: "Importe les employeeNo déjà enregistrés sur une pointeuse et propose de les lier",
)]
class SyncDeviceEmployeesCommand extends Command
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly EmployeeSyncService $employeeSync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('deviceId', InputArgument::REQUIRED, 'ID du device en base');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $device = $this->devices->find($input->getArgument('deviceId'));
        if (! $device) {
            $io->error('Device introuvable.');
            return Command::FAILURE;
        }

        $unlinked = $this->employeeSync->previewUnlinked($device);

        if (empty($unlinked)) {
            $io->warning('Aucun utilisateur non lié trouvé sur ce device.');
            return Command::SUCCESS;
        }

        $io->info(count($unlinked) . " utilisateurs non liés trouvés sur {$device->getName()}.");

        $selected = [];
        foreach ($unlinked as $entry) {
            if ($io->confirm("Lier employeeNo={$entry['employeeNo']} ({$entry['name']}) à un nouvel Employee ?", true)) {
                $selected[] = $entry;
            }
        }

        $created = $this->employeeSync->linkSelected($device, $selected);

        foreach ($created as $employee) {
            $io->text("→ Créé et lié: {$employee->getFullName()}");
        }

        return Command::SUCCESS;
    }
}
