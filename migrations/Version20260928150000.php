<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute alert_settings.very_late_threshold_minutes (seuil configurable pour l'alerte \"trop en retard\")";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE alert_settings ADD very_late_threshold_minutes INT DEFAULT 60 NOT NULL");
        $this->addSql("UPDATE alert_settings SET very_late_threshold_minutes = 60");
        $this->addSql("ALTER TABLE alert_settings CHANGE very_late_threshold_minutes very_late_threshold_minutes INT NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert_settings DROP very_late_threshold_minutes');
    }
}
