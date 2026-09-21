<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260827102412 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE employees DROP FOREIGN KEY FK_BA82C300BBCA2216');
        $this->addSql('ALTER TABLE employees ADD CONSTRAINT FK_BA82C300BBCA2216 FOREIGN KEY (work_schedule_id) REFERENCES work_schedules (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE employees DROP FOREIGN KEY FK_BA82C300BBCA2216');
        $this->addSql('ALTER TABLE employees ADD CONSTRAINT FK_BA82C300BBCA2216 FOREIGN KEY (work_schedule_id) REFERENCES work_schedules (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
    }
}
