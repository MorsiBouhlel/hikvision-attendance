<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928105021 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute Department.isRemote (departement en teletravail)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE departments ADD is_remote TINYINT(1) NOT NULL DEFAULT 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE departments DROP is_remote');
    }
}
