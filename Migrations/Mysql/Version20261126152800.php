<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126152800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ISP.Lanyard: tickets with QR code, one per person';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('CREATE TABLE isp_lanyard_domain_model_ticket (persistence_object_identifier VARCHAR(40) NOT NULL, lanyard_order VARCHAR(40) NOT NULL, orderitem VARCHAR(40) NOT NULL, number INT NOT NULL, code VARCHAR(40) NOT NULL, status VARCHAR(20) NOT NULL, issuedat DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', cancelledat DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_531446623473A377 (lanyard_order), INDEX IDX_53144662112B7384 (orderitem), UNIQUE INDEX UNIQ_5314466277153098 (code), PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_ticket ADD CONSTRAINT FK_531446623473A377 FOREIGN KEY (lanyard_order) REFERENCES isp_lanyard_domain_model_order (persistence_object_identifier) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_ticket ADD CONSTRAINT FK_53144662112B7384 FOREIGN KEY (orderitem) REFERENCES isp_lanyard_domain_model_orderitem (persistence_object_identifier) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('DROP TABLE isp_lanyard_domain_model_ticket');
    }
}
