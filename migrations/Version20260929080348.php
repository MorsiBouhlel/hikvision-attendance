<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260929080348 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout du token de définition de mot de passe (users.reset_token / reset_token_expires_at) pour l\'invitation par email des nouveaux comptes, + rattrapage du drift de schéma préexistant.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE attendance_corrections CHANGE occurred_at occurred_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE attendance_corrections RENAME INDEX idx_attendance_corrections_user TO IDX_CDBC881FA76ED395');
        $this->addSql('ALTER TABLE department_breaks RENAME INDEX idx_department_breaks_department TO IDX_691E7761AE80F5DF');
        $this->addSql('ALTER TABLE departments CHANGE is_remote is_remote TINYINT(1) NOT NULL');
        $this->addSql('ALTER TABLE employees CHANGE is_tracking_enabled is_tracking_enabled TINYINT(1) NOT NULL');
        $this->addSql('ALTER TABLE users ADD reset_token VARCHAR(64) DEFAULT NULL, ADD reset_token_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE locale locale VARCHAR(5) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9D7C8DC19 ON users (reset_token)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE department_breaks RENAME INDEX idx_691e7761ae80f5df TO IDX_DEPARTMENT_BREAKS_DEPARTMENT');
        $this->addSql('ALTER TABLE departments CHANGE is_remote is_remote TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE employees CHANGE is_tracking_enabled is_tracking_enabled TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('DROP INDEX UNIQ_1483A5E9D7C8DC19 ON users');
        $this->addSql('ALTER TABLE users DROP reset_token, DROP reset_token_expires_at, CHANGE locale locale VARCHAR(5) DEFAULT \'fr\' NOT NULL');
        $this->addSql('ALTER TABLE attendance_corrections CHANGE occurred_at occurred_at DATETIME NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE attendance_corrections RENAME INDEX idx_cdbc881fa76ed395 TO IDX_ATTENDANCE_CORRECTIONS_USER');
    }
}
