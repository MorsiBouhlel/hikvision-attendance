<?php

namespace App\Command;

use App\Repository\DeviceEmployeeRepository;
use App\Repository\DeviceRepository;
use App\Service\EmployeePhotoService;
use App\Service\HikvisionClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:hikvision:sync-employee-photos',
    description: "Télécharge la photo d'enrôlement des employés déjà liés à une pointeuse mais qui n'en ont pas encore une",
)]
class SyncEmployeePhotosCommand extends Command
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly DeviceEmployeeRepository $deviceEmployees,
        private readonly HikvisionClientFactory $clientFactory,
        private readonly EmployeePhotoService $photos,
        private readonly EntityManagerInterface $em,
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

        $client = $this->clientFactory->forDevice($device);
        $users = $client->listUsers();
        $usersByEmployeeNo = [];
        foreach ($users as $user) {
            if (! empty($user['employeeNo'])) {
                $usersByEmployeeNo[$user['employeeNo']] = $user;
            }
        }

        $links = $this->deviceEmployees->findBy(['device' => $device]);

        $downloaded = 0;
        $skipped = 0;

        foreach ($links as $link) {
            $employee = $link->getEmployee();

            if ($employee->getPhotoPath()) {
                $skipped++;
                continue;
            }

            $user = $usersByEmployeeNo[$link->getEmployeeNo()] ?? null;
            $faceUrl = $user['faceURL'] ?? null;

            if (! $faceUrl) {
                $skipped++;
                continue;
            }

            try {
                $this->photos->store($employee, $faceUrl, $client);
                $downloaded++;
                $io->text("→ Photo téléchargée pour {$employee->getFullName()}");
            } catch (\Throwable $e) {
                $io->warning("Échec pour {$employee->getFullName()}: {$e->getMessage()}");
                $skipped++;
            }
        }

        $this->em->flush();

        $io->success("{$downloaded} photo(s) téléchargée(s), {$skipped} ignorée(s) (déjà présente ou aucune photo disponible).");

        return Command::SUCCESS;
    }
}
