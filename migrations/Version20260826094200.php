<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260826094200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE employees ADD photo_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE work_schedules CHANGE check_window_margin_minutes check_window_margin_minutes INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE work_schedules CHANGE check_window_margin_minutes check_window_margin_minutes INT DEFAULT 240 NOT NULL');
        $this->addSql('ALTER TABLE employees DROP photo_path');
    }
}
