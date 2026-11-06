<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261106154934 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE organization CHANGE primary_color primary_color VARCHAR(7) DEFAULT \'#0f4c5c\' NOT NULL, CHANGE secondary_color secondary_color VARCHAR(7) DEFAULT \'#0f3542\' NOT NULL');
        // Seules les organisations restées sur les anciennes couleurs par défaut changent.
        $this->addSql("UPDATE organization SET primary_color = '#0f4c5c' WHERE primary_color = '#2346d5'");
        $this->addSql("UPDATE organization SET secondary_color = '#0f3542' WHERE secondary_color = '#14222f'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE organization CHANGE primary_color primary_color VARCHAR(7) DEFAULT \'#2346d5\' NOT NULL, CHANGE secondary_color secondary_color VARCHAR(7) DEFAULT \'#14222f\' NOT NULL');
    }
}
