<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261106105506 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE organization (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(50) NOT NULL, name VARCHAR(120) NOT NULL, notification_email VARCHAR(180) DEFAULT NULL, bot_enabled TINYINT NOT NULL, bot_message LONGTEXT NOT NULL, UNIQUE INDEX UNIQ_C1EE637C989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('DROP TABLE settings');
        $this->addSql('ALTER TABLE app_user ADD super_admin TINYINT NOT NULL, ADD organization_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_88BDF3E932C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_88BDF3E932C8A3DE ON app_user (organization_id)');
        $this->addSql('DROP INDEX UNIQ_C7440455444F97DD ON client');
        $this->addSql('DROP INDEX UNIQ_C7440455E7927C74 ON client');
        $this->addSql('ALTER TABLE client ADD organization_id INT NOT NULL');
        $this->addSql('ALTER TABLE client ADD CONSTRAINT FK_C744045532C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE');
        $this->addSql('CREATE UNIQUE INDEX uniq_client_organization_email ON client (organization_id, email)');
        $this->addSql('CREATE UNIQUE INDEX uniq_client_organization_phone ON client (organization_id, phone)');
        $this->addSql('CREATE INDEX IDX_C744045532C8A3DE ON client (organization_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE settings (id INT AUTO_INCREMENT NOT NULL, bot_enabled TINYINT NOT NULL, bot_message LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('DROP TABLE organization');
        $this->addSql('ALTER TABLE app_user DROP FOREIGN KEY FK_88BDF3E932C8A3DE');
        $this->addSql('DROP INDEX IDX_88BDF3E932C8A3DE ON app_user');
        $this->addSql('ALTER TABLE app_user DROP super_admin, DROP organization_id');
        $this->addSql('ALTER TABLE client DROP FOREIGN KEY FK_C744045532C8A3DE');
        $this->addSql('DROP INDEX uniq_client_organization_email ON client');
        $this->addSql('DROP INDEX uniq_client_organization_phone ON client');
        $this->addSql('DROP INDEX IDX_C744045532C8A3DE ON client');
        $this->addSql('ALTER TABLE client DROP organization_id');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C7440455444F97DD ON client (phone)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C7440455E7927C74 ON client (email)');
    }
}
