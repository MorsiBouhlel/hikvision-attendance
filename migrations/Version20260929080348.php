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
        // Renommage conditionnel : le nom réel de cet index diverge déjà entre
        // environnements (drift préexistant, cf. CLAUDE.md), donc on ne renomme
        // que si la source existe encore et que la cible n'est pas déjà là —
        // sinon ALTER TABLE ... RENAME INDEX plante avec "Key ... doesn't exist".
        $this->renameIndexIfNeeded('attendance_corrections', 'idx_attendance_corrections_user', 'IDX_CDBC881FA76ED395');
        $this->renameIndexIfNeeded('department_breaks', 'idx_department_breaks_department', 'IDX_691E7761AE80F5DF');
        $this->addSql('ALTER TABLE departments CHANGE is_remote is_remote TINYINT(1) NOT NULL');
        $this->addSql('ALTER TABLE employees CHANGE is_tracking_enabled is_tracking_enabled TINYINT(1) NOT NULL');
        $this->addSql('ALTER TABLE users ADD reset_token VARCHAR(64) DEFAULT NULL, ADD reset_token_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE locale locale VARCHAR(5) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9D7C8DC19 ON users (reset_token)');
    }

    /**
     * MySQL compare les noms d'index de façon insensible à la casse pour
     * RENAME INDEX, mais échoue si le nom source n'existe pas (ex: déjà
     * renommé par un run précédent, ou schéma créé avec un nom différent).
     */
    private function renameIndexIfNeeded(string $table, string $from, string $to): void
    {
        $indexes = array_map(
            static fn (array $row) => $row['Key_name'],
            $this->connection->fetchAllAssociative("SHOW INDEX FROM {$table}"),
        );

        // MySQL est insensible à la casse sur les noms d'index (RENAME INDEX
        // accepte n'importe quelle casse en entrée) — comparer pareil ici.
        $hasIndexNamed = static function (array $indexes, string $name): bool {
            foreach ($indexes as $existing) {
                if (strcasecmp($existing, $name) === 0) {
                    return true;
                }
            }
            return false;
        };

        if ($hasIndexNamed($indexes, $to)) {
            return; // déjà au bon nom
        }

        if (! $hasIndexNamed($indexes, $from)) {
            // ni l'ancien ni le nouveau nom ne matchent : schéma déjà divergent,
            // on ne casse pas la migration pour un simple nom d'index cosmétique.
            return;
        }

        $this->addSql("ALTER TABLE {$table} RENAME INDEX {$from} TO {$to}");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->renameIndexIfNeeded('department_breaks', 'idx_691e7761ae80f5df', 'IDX_DEPARTMENT_BREAKS_DEPARTMENT');
        $this->addSql('ALTER TABLE departments CHANGE is_remote is_remote TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE employees CHANGE is_tracking_enabled is_tracking_enabled TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('DROP INDEX UNIQ_1483A5E9D7C8DC19 ON users');
        $this->addSql('ALTER TABLE users DROP reset_token, DROP reset_token_expires_at, CHANGE locale locale VARCHAR(5) DEFAULT \'fr\' NOT NULL');
        $this->addSql('ALTER TABLE attendance_corrections CHANGE occurred_at occurred_at DATETIME NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->renameIndexIfNeeded('attendance_corrections', 'idx_cdbc881fa76ed395', 'IDX_ATTENDANCE_CORRECTIONS_USER');
    }
}
