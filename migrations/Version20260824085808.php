<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260824085808 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE attendance_events (id INT AUTO_INCREMENT NOT NULL, device_id INT NOT NULL, employee_id INT DEFAULT NULL, employee_no VARCHAR(50) DEFAULT NULL, serial_no VARCHAR(100) DEFAULT NULL, verify_mode VARCHAR(30) DEFAULT NULL, event_type VARCHAR(60) DEFAULT NULL, minor INT DEFAULT NULL, success TINYINT(1) NOT NULL, occurred_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', raw_payload JSON DEFAULT NULL, INDEX IDX_F9AD4C4E94A4C7D4 (device_id), INDEX IDX_F9AD4C4E8C03F15C (employee_id), INDEX idx_employee_occurred (employee_id, occurred_at), UNIQUE INDEX uniq_device_serial (device_id, serial_no), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE device_employee (id INT AUTO_INCREMENT NOT NULL, device_id INT NOT NULL, employee_id INT NOT NULL, employee_no VARCHAR(50) NOT NULL, INDEX IDX_D3AB2C8194A4C7D4 (device_id), INDEX IDX_D3AB2C818C03F15C (employee_id), UNIQUE INDEX uniq_device_employee_no (device_id, employee_no), UNIQUE INDEX uniq_device_employee (device_id, employee_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE devices (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, site VARCHAR(100) DEFAULT NULL, ip_address VARCHAR(45) NOT NULL, port INT NOT NULL, admin_user VARCHAR(50) NOT NULL, admin_password LONGTEXT NOT NULL, serial_number VARCHAR(100) DEFAULT NULL, webhook_token VARCHAR(40) NOT NULL, is_active TINYINT(1) NOT NULL, last_seen_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_11074E9AD5E74442 (webhook_token), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE employees (id INT AUTO_INCREMENT NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, department VARCHAR(100) DEFAULT NULL, is_active TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE attendance_events ADD CONSTRAINT FK_F9AD4C4E94A4C7D4 FOREIGN KEY (device_id) REFERENCES devices (id)');
        $this->addSql('ALTER TABLE attendance_events ADD CONSTRAINT FK_F9AD4C4E8C03F15C FOREIGN KEY (employee_id) REFERENCES employees (id)');
        $this->addSql('ALTER TABLE device_employee ADD CONSTRAINT FK_D3AB2C8194A4C7D4 FOREIGN KEY (device_id) REFERENCES devices (id)');
        $this->addSql('ALTER TABLE device_employee ADD CONSTRAINT FK_D3AB2C818C03F15C FOREIGN KEY (employee_id) REFERENCES employees (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE attendance_events DROP FOREIGN KEY FK_F9AD4C4E94A4C7D4');
        $this->addSql('ALTER TABLE attendance_events DROP FOREIGN KEY FK_F9AD4C4E8C03F15C');
        $this->addSql('ALTER TABLE device_employee DROP FOREIGN KEY FK_D3AB2C8194A4C7D4');
        $this->addSql('ALTER TABLE device_employee DROP FOREIGN KEY FK_D3AB2C818C03F15C');
        $this->addSql('DROP TABLE attendance_events');
        $this->addSql('DROP TABLE device_employee');
        $this->addSql('DROP TABLE devices');
        $this->addSql('DROP TABLE employees');
    }
}
