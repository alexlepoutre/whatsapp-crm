<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261106161334 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE organization CHANGE secondary_color secondary_color VARCHAR(7) DEFAULT \'#cfeae3\' NOT NULL');
        // Seules les organisations restées sur l'ancienne couleur par défaut changent.
        $this->addSql("UPDATE organization SET secondary_color = '#cfeae3' WHERE secondary_color = '#0f3542'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE organization CHANGE secondary_color secondary_color VARCHAR(7) DEFAULT \'#0f3542\' NOT NULL');
    }
}
