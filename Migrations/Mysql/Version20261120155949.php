<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120155949 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ISP.Lanyard: online payment (payment reference and return token on order)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('ALTER TABLE isp_lanyard_domain_model_order ADD paymentreference VARCHAR(255) DEFAULT NULL, ADD paymenttoken VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2088EA6123914654 ON isp_lanyard_domain_model_order (paymenttoken)');
        $this->addSql('CREATE INDEX paymentreference_idx ON isp_lanyard_domain_model_order (paymentreference)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('DROP INDEX UNIQ_2088EA6123914654 ON isp_lanyard_domain_model_order');
        $this->addSql('DROP INDEX paymentreference_idx ON isp_lanyard_domain_model_order');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_order DROP paymentreference, DROP paymenttoken');
    }
}
