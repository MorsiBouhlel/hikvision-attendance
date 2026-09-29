<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260929142616 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE remote_work_requests (id INT AUTO_INCREMENT NOT NULL, employee_id INT NOT NULL, decided_by_id INT DEFAULT NULL, start_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', end_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', reason VARCHAR(255) DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', decided_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', decision_comment VARCHAR(255) DEFAULT NULL, INDEX IDX_54EDA3CD8C03F15C (employee_id), INDEX IDX_54EDA3CDE26B496B (decided_by_id), INDEX idx_remote_work_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE remote_work_requests ADD CONSTRAINT FK_54EDA3CD8C03F15C FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE remote_work_requests ADD CONSTRAINT FK_54EDA3CDE26B496B FOREIGN KEY (decided_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE remote_work_requests DROP FOREIGN KEY FK_54EDA3CD8C03F15C');
        $this->addSql('ALTER TABLE remote_work_requests DROP FOREIGN KEY FK_54EDA3CDE26B496B');
        $this->addSql('DROP TABLE remote_work_requests');
    }
}
