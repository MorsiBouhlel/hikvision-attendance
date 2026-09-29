<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260929141018 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE leave_adjustments (id INT AUTO_INCREMENT NOT NULL, employee_id INT NOT NULL, created_by_id INT DEFAULT NULL, year INT NOT NULL, days DOUBLE PRECISION NOT NULL, reason VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_5C8566758C03F15C (employee_id), INDEX IDX_5C856675B03A8386 (created_by_id), INDEX idx_leave_adjustment_employee_year (employee_id, year), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE leave_requests (id INT AUTO_INCREMENT NOT NULL, employee_id INT NOT NULL, decided_by_id INT DEFAULT NULL, leave_id INT DEFAULT NULL, start_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', end_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', type VARCHAR(30) NOT NULL, reason VARCHAR(255) DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', decided_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', decision_comment VARCHAR(255) DEFAULT NULL, INDEX IDX_45ADFEF28C03F15C (employee_id), INDEX IDX_45ADFEF2E26B496B (decided_by_id), UNIQUE INDEX UNIQ_45ADFEF21B2ADB5C (leave_id), INDEX idx_leave_request_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE leave_adjustments ADD CONSTRAINT FK_5C8566758C03F15C FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE leave_adjustments ADD CONSTRAINT FK_5C856675B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE leave_requests ADD CONSTRAINT FK_45ADFEF28C03F15C FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE leave_requests ADD CONSTRAINT FK_45ADFEF2E26B496B FOREIGN KEY (decided_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE leave_requests ADD CONSTRAINT FK_45ADFEF21B2ADB5C FOREIGN KEY (leave_id) REFERENCES leaves (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE employees ADD annual_leave_days DOUBLE PRECISION DEFAULT \'22\' NOT NULL, ADD hire_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE leave_adjustments DROP FOREIGN KEY FK_5C8566758C03F15C');
        $this->addSql('ALTER TABLE leave_adjustments DROP FOREIGN KEY FK_5C856675B03A8386');
        $this->addSql('ALTER TABLE leave_requests DROP FOREIGN KEY FK_45ADFEF28C03F15C');
        $this->addSql('ALTER TABLE leave_requests DROP FOREIGN KEY FK_45ADFEF2E26B496B');
        $this->addSql('ALTER TABLE leave_requests DROP FOREIGN KEY FK_45ADFEF21B2ADB5C');
        $this->addSql('DROP TABLE leave_adjustments');
        $this->addSql('DROP TABLE leave_requests');
        $this->addSql('ALTER TABLE employees DROP annual_leave_days, DROP hire_date');
    }
}
