<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261106101753 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_88BDF3E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE client (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(20) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_C7440455E7927C74 (email), UNIQUE INDEX UNIQ_C7440455444F97DD (phone), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE conversation (id INT AUTO_INCREMENT NOT NULL, channel VARCHAR(20) NOT NULL, subject VARCHAR(150) DEFAULT NULL, status VARCHAR(20) NOT NULL, access_token VARCHAR(64) DEFAULT NULL, last_inbound_at DATETIME DEFAULT NULL, last_message_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, client_id INT NOT NULL, UNIQUE INDEX UNIQ_8A8E26E9B6A2DD68 (access_token), INDEX IDX_8A8E26E97B00651C6F60E3AF (status, last_message_at), INDEX IDX_8A8E26E919EB6921 (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE message (id INT AUTO_INCREMENT NOT NULL, sender VARCHAR(20) NOT NULL, body LONGTEXT NOT NULL, wa_message_id VARCHAR(191) DEFAULT NULL, delivery_status VARCHAR(20) DEFAULT NULL, failure_reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, conversation_id INT NOT NULL, UNIQUE INDEX UNIQ_B6BD307F20B24E84 (wa_message_id), INDEX IDX_B6BD307F9AC0396 (conversation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE settings (id INT AUTO_INCREMENT NOT NULL, bot_enabled TINYINT NOT NULL, bot_message LONGTEXT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE conversation ADD CONSTRAINT FK_8A8E26E919EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F9AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE conversation DROP FOREIGN KEY FK_8A8E26E919EB6921');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307F9AC0396');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE client');
        $this->addSql('DROP TABLE conversation');
        $this->addSql('DROP TABLE message');
        $this->addSql('DROP TABLE settings');
    }
}
