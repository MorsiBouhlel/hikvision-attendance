<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260826110932 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE alert_logs (id INT AUTO_INCREMENT NOT NULL, employee_id INT NOT NULL, date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', type VARCHAR(10) NOT NULL, sent_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_7F3F14D88C03F15C (employee_id), UNIQUE INDEX uniq_alert_employee_date_type (employee_id, date, type), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE alert_logs ADD CONSTRAINT FK_7F3F14D88C03F15C FOREIGN KEY (employee_id) REFERENCES employees (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alert_logs DROP FOREIGN KEY FK_7F3F14D88C03F15C');
        $this->addSql('DROP TABLE alert_logs');
    }
}
