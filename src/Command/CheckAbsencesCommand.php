<?php

namespace App\Command;

use App\Service\AttendanceAlertService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:hikvision:check-absences',
    description: "Envoie une alerte Telegram groupée listant les employés actifs n'ayant pas encore pointé aujourd'hui (destinée à tourner via cron en milieu de matinée)",
)]
class CheckAbsencesCommand extends Command
{
    public function __construct(private readonly AttendanceAlertService $alerts)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = $this->alerts->checkAbsences(new \DateTimeImmutable('today'));

        $io->success($count > 0
            ? "Alerte groupée envoyée pour {$count} employé(s) absent(s)."
            : 'Aucune nouvelle absence à signaler.');

        return Command::SUCCESS;
    }
}
