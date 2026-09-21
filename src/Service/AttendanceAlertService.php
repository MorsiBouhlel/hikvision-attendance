<?php

namespace App\Service;

use App\Entity\AlertLog;
use App\Entity\AttendanceEvent;
use App\Entity\Employee;
use App\Repository\AlertLogRepository;
use App\Repository\AlertSettingsRepository;
use App\Repository\TelegramRecipientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramOptions;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

class AttendanceAlertService
{
    public function __construct(
        private readonly AttendanceService $attendanceService,
        private readonly AlertLogRepository $alertLogs,
        private readonly AlertSettingsRepository $settings,
        private readonly TelegramRecipientRepository $recipients,
        private readonly ChatterInterface $chatter,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Vérifie si le pointage qui vient d'arriver rend son employé "en
     * retard" aujourd'hui, et envoie une alerte si ce n'est pas déjà fait.
     * À appeler uniquement pour un événement réellement nouveau et daté du
     * jour même — les rattrapages historiques ne doivent jamais déclencher
     * d'alerte.
     */
    public function checkLate(AttendanceEvent $event): void
    {
        $employee = $event->getEmployee();
        if (! $employee) {
            return;
        }

        $date = $event->getOccurredAt()->setTime(0, 0, 0);
        $summary = $this->attendanceService->dailySummary($employee, $date);

        if ($summary['status'] !== 'late') {
            return;
        }

        $this->send($employee, $date, 'late', $summary['late_minutes']);
    }

    /**
     * Envoie une seule alerte Telegram groupée listant tous les employés
     * absents à $date (au lieu d'un message par employé) — les employés
     * déjà signalés absents ce jour (AlertLog existant) sont exclus, donc
     * un second passage le même jour sans nouvel absent n'envoie rien.
     *
     * @return int nombre d'employés inclus dans le message envoyé
     */
    public function checkAbsences(\DateTimeImmutable $date): int
    {
        if (! $this->settings->isEnabled()) {
            return 0;
        }

        $employees = array_values(array_filter(
            $this->attendanceService->missingToday(),
            fn (Employee $e) => ! $this->alertLogs->alreadySent($e, $date, 'absent')
        ));

        if (empty($employees)) {
            return 0;
        }

        $day = $date->format('d/m/Y');
        $lines = array_map(
            fn (Employee $e) => '• ' . $e->getFullName() . ($e->getDepartment() ? " ({$e->getDepartment()->getName()})" : ''),
            $employees
        );
        $text = "❌ Absences du {$day} (" . count($employees) . ") :\n" . implode("\n", $lines);

        $result = $this->dispatchToRecipients($text);

        if ($result['success'] === 0) {
            return 0;
        }

        foreach ($employees as $employee) {
            $log = new AlertLog();
            $log->setEmployee($employee);
            $log->setDate($date->setTime(0, 0, 0));
            $log->setType('absent');
            $log->setSentAt(new \DateTimeImmutable());
            $this->em->persist($log);
        }
        $this->em->flush();

        return count($employees);
    }

    /**
     * Envoie un message générique à tous les destinataires actifs, pour
     * vérifier la configuration depuis l'UI sans attendre un vrai
     * retard/absence.
     *
     * @return array{success: int, failed: int}
     */
    public function sendTest(): array
    {
        return $this->dispatchToRecipients('✅ Test de configuration — les alertes de présence sont bien reçues.');
    }

    private function send(Employee $employee, \DateTimeImmutable $date, string $type, ?int $lateMinutes = null): bool
    {
        if (! $this->settings->isEnabled()) {
            return false;
        }

        if ($this->alertLogs->alreadySent($employee, $date, $type)) {
            return false;
        }

        $day = $date->format('d/m/Y');

        $text = $type === 'late'
            ? "⏰ Retard : {$employee->getFullName()} ({$day})" . ($lateMinutes ? " — {$lateMinutes} min" : '')
            : "❌ Absence : {$employee->getFullName()} n'a pointé sur aucune pointeuse le {$day}";

        if ($employee->getDepartment()) {
            $text .= " · {$employee->getDepartment()}";
        }

        $result = $this->dispatchToRecipients($text);

        if ($result['success'] === 0) {
            return false;
        }

        $log = new AlertLog();
        $log->setEmployee($employee);
        $log->setDate($date->setTime(0, 0, 0));
        $log->setType($type);
        $log->setSentAt(new \DateTimeImmutable());
        $this->em->persist($log);
        $this->em->flush();

        return true;
    }

    /**
     * Envoie $text à chaque destinataire actif — un message par
     * destinataire (chat_id fixé par message, pas par transport), un échec
     * sur l'un d'eux n'empêche jamais l'envoi aux autres.
     *
     * @return array{success: int, failed: int}
     */
    private function dispatchToRecipients(string $text): array
    {
        $escaped = $this->escapeHtml($text);
        $success = 0;
        $failed = 0;

        foreach ($this->recipients->findActive() as $recipient) {
            $message = new ChatMessage($escaped);
            $message->options((new TelegramOptions())
                ->parseMode(TelegramOptions::PARSE_MODE_HTML)
                ->chatId($recipient->getChatId()));

            try {
                $this->chatter->send($message);
                $success++;
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->warning('Échec envoi alerte Telegram', [
                    'recipient_id' => $recipient->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['success' => $success, 'failed' => $failed];
    }

    /**
     * MarkdownV2 délibérément évité: TelegramTransport::doSend() applique
     * sa propre regex d'échappement (`/([.!#>+-=|{}~])/`) dès que
     * parse_mode=MarkdownV2 est actif (ou même par défaut si absent) —
     * mais `+-=` dans cette classe de caractères PCRE définit une PLAGE
     * (`+` à `=` en ASCII), pas trois caractères littéraux, ce qui
     * échappe accidentellement tous les chiffres et le `/` en plus de la
     * ponctuation prévue (confirmé en conditions réelles: un message
     * contenant une date type "26/08/2026" ressortait complètement
     * charcuté, un caractère "\" devant chaque chiffre). C'est un bug
     * dans symfony/telegram-notifier, pas quelque chose qu'on peut
     * contourner proprement côté appelant. HTML n'a pas ce problème: Telegram
     * ne réserve que & < > en mode HTML, et on n'a besoin d'aucune mise en
     * forme (gras/italique) pour ces messages.
     */
    private function escapeHtml(string $text): string
    {
        // Telegram en mode HTML ne réserve que & < > (pas les guillemets).
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
    }
}
