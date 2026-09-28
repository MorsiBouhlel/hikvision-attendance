<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute Employee.isTrackingEnabled (employes non concernes par le pointage)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE employees ADD is_tracking_enabled TINYINT(1) NOT NULL DEFAULT 1");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE employees DROP is_tracking_enabled');
    }
}
