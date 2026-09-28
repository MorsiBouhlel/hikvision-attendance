<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute department_breaks (pauses par departement, deduites automatiquement du temps travaille)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE department_breaks (id INT AUTO_INCREMENT NOT NULL, department_id INT NOT NULL, label VARCHAR(100) NOT NULL, duration_minutes INT NOT NULL, INDEX IDX_DEPARTMENT_BREAKS_DEPARTMENT (department_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("ALTER TABLE department_breaks ADD CONSTRAINT FK_DEPARTMENT_BREAKS_DEPARTMENT FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE department_breaks DROP FOREIGN KEY FK_DEPARTMENT_BREAKS_DEPARTMENT');
        $this->addSql('DROP TABLE department_breaks');
    }
}
