<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tabelle für den Änderungsverlauf von Bestellungen.
 */
final class Version20261027141557 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ISP.Lanyard: order history (OrderLogEntry)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('CREATE TABLE isp_lanyard_domain_model_orderlogentry (persistence_object_identifier VARCHAR(40) NOT NULL, lanyard_order VARCHAR(40) NOT NULL, createdat DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', action VARCHAR(30) NOT NULL, actortype VARCHAR(20) NOT NULL, actorname VARCHAR(255) DEFAULT NULL, message LONGTEXT DEFAULT NULL, INDEX IDX_EFD9E3D33473A377 (lanyard_order), INDEX order_created (lanyard_order, createdat), PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_orderlogentry ADD CONSTRAINT FK_EFD9E3D33473A377 FOREIGN KEY (lanyard_order) REFERENCES isp_lanyard_domain_model_order (persistence_object_identifier) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('DROP TABLE isp_lanyard_domain_model_orderlogentry');
    }
}
