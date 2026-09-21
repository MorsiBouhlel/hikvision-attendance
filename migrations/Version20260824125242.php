<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260824125242 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE holidays (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', label VARCHAR(150) NOT NULL, UNIQUE INDEX uniq_holiday_date (date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE leaves (id INT AUTO_INCREMENT NOT NULL, employee_id INT NOT NULL, start_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', end_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', type VARCHAR(30) NOT NULL, reason VARCHAR(255) DEFAULT NULL, INDEX IDX_9D46AD5F8C03F15C (employee_id), INDEX idx_leave_employee_dates (employee_id, start_date, end_date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE work_schedules (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, start_time TIME NOT NULL COMMENT \'(DC2Type:time_immutable)\', end_time TIME NOT NULL COMMENT \'(DC2Type:time_immutable)\', tolerance_minutes INT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE leaves ADD CONSTRAINT FK_9D46AD5F8C03F15C FOREIGN KEY (employee_id) REFERENCES employees (id)');
        $this->addSql('ALTER TABLE employees ADD work_schedule_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE employees ADD CONSTRAINT FK_BA82C300BBCA2216 FOREIGN KEY (work_schedule_id) REFERENCES work_schedules (id)');
        $this->addSql('CREATE INDEX IDX_BA82C300BBCA2216 ON employees (work_schedule_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE employees DROP FOREIGN KEY FK_BA82C300BBCA2216');
        $this->addSql('ALTER TABLE leaves DROP FOREIGN KEY FK_9D46AD5F8C03F15C');
        $this->addSql('DROP TABLE holidays');
        $this->addSql('DROP TABLE leaves');
        $this->addSql('DROP TABLE work_schedules');
        $this->addSql('DROP INDEX IDX_BA82C300BBCA2216 ON employees');
        $this->addSql('ALTER TABLE employees DROP work_schedule_id');
    }
}
