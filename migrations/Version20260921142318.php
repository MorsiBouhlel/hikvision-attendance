<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260921142318 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE work_schedule_days (id INT AUTO_INCREMENT NOT NULL, work_schedule_id INT NOT NULL, day_of_week INT NOT NULL, is_rest_day TINYINT(1) NOT NULL, start_time TIME DEFAULT NULL COMMENT \'(DC2Type:time_immutable)\', end_time TIME DEFAULT NULL COMMENT \'(DC2Type:time_immutable)\', INDEX IDX_4B71A509BBCA2216 (work_schedule_id), UNIQUE INDEX UNIQ_4B71A509BBCA22166A79171 (work_schedule_id, day_of_week), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE work_schedule_days ADD CONSTRAINT FK_4B71A509BBCA2216 FOREIGN KEY (work_schedule_id) REFERENCES work_schedules (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE work_schedule_days DROP FOREIGN KEY FK_4B71A509BBCA2216');
        $this->addSql('DROP TABLE work_schedule_days');
    }
}
