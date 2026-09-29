<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260929161834 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE hr_settings (id INT AUTO_INCREMENT NOT NULL, remote_work_days_per_month INT DEFAULT 8 NOT NULL, permission_hours_per_month DOUBLE PRECISION DEFAULT \'4\' NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE permission_requests (id INT AUTO_INCREMENT NOT NULL, employee_id INT NOT NULL, decided_by_id INT DEFAULT NULL, date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', start_time TIME NOT NULL COMMENT \'(DC2Type:time_immutable)\', end_time TIME NOT NULL COMMENT \'(DC2Type:time_immutable)\', reason VARCHAR(255) DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', decided_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', decision_comment VARCHAR(255) DEFAULT NULL, INDEX IDX_8EBD03E78C03F15C (employee_id), INDEX IDX_8EBD03E7E26B496B (decided_by_id), INDEX idx_permission_status (status), INDEX idx_permission_employee_date (employee_id, date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE permission_requests ADD CONSTRAINT FK_8EBD03E78C03F15C FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE permission_requests ADD CONSTRAINT FK_8EBD03E7E26B496B FOREIGN KEY (decided_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE employees ADD remote_work_quota_override INT DEFAULT NULL, ADD permission_quota_override DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE permission_requests DROP FOREIGN KEY FK_8EBD03E78C03F15C');
        $this->addSql('ALTER TABLE permission_requests DROP FOREIGN KEY FK_8EBD03E7E26B496B');
        $this->addSql('DROP TABLE hr_settings');
        $this->addSql('DROP TABLE permission_requests');
        $this->addSql('ALTER TABLE employees DROP remote_work_quota_override, DROP permission_quota_override');
    }
}
