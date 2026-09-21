<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260824133040 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE attendance_events ADD attendance_status VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE work_schedules ADD check_window_margin_minutes INT NOT NULL DEFAULT 240');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE attendance_events DROP attendance_status');
        $this->addSql('ALTER TABLE work_schedules DROP check_window_margin_minutes');
    }
}
