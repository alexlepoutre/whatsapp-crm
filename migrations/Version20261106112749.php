<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261106112749 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE organization_logo (id INT AUTO_INCREMENT NOT NULL, mime_type VARCHAR(20) NOT NULL, content LONGBLOB NOT NULL, organization_id INT NOT NULL, UNIQUE INDEX UNIQ_DD87A48E32C8A3DE (organization_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE organization_logo ADD CONSTRAINT FK_DD87A48E32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE organization ADD primary_color VARCHAR(7) DEFAULT \'#2346d5\' NOT NULL, ADD secondary_color VARCHAR(7) DEFAULT \'#14222f\' NOT NULL, ADD logo_version INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE organization_logo DROP FOREIGN KEY FK_DD87A48E32C8A3DE');
        $this->addSql('DROP TABLE organization_logo');
        $this->addSql('ALTER TABLE organization DROP primary_color, DROP secondary_color, DROP logo_version');
    }
}
