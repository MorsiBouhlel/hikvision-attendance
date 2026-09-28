<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute attendance_corrections (audit des corrections manuelles de pointage : qui, quand, pourquoi)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE attendance_corrections (id INT AUTO_INCREMENT NOT NULL, employee_id INT NOT NULL, user_id INT NOT NULL, action VARCHAR(20) NOT NULL, occurred_at DATETIME NOT NULL, attendance_status VARCHAR(30) DEFAULT NULL, reason LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_correction_employee (employee_id), INDEX IDX_ATTENDANCE_CORRECTIONS_USER (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("ALTER TABLE attendance_corrections ADD CONSTRAINT FK_ATTENDANCE_CORRECTIONS_EMPLOYEE FOREIGN KEY (employee_id) REFERENCES employees (id)");
        $this->addSql("ALTER TABLE attendance_corrections ADD CONSTRAINT FK_ATTENDANCE_CORRECTIONS_USER FOREIGN KEY (user_id) REFERENCES users (id)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attendance_corrections DROP FOREIGN KEY FK_ATTENDANCE_CORRECTIONS_EMPLOYEE');
        $this->addSql('ALTER TABLE attendance_corrections DROP FOREIGN KEY FK_ATTENDANCE_CORRECTIONS_USER');
        $this->addSql('DROP TABLE attendance_corrections');
    }
}
