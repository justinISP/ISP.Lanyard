<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125090720 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ISP.Lanyard: payment transaction id and return secret on order (PAYONE)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('ALTER TABLE isp_lanyard_domain_model_order ADD paymenttransactionid VARCHAR(255) DEFAULT NULL, ADD paymentreturnsecret VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('ALTER TABLE isp_lanyard_domain_model_order DROP paymenttransactionid, DROP paymentreturnsecret');
    }
}
