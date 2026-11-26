<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126105448 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ISP.Lanyard: form page on order (return URL stays short)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('ALTER TABLE isp_lanyard_domain_model_order ADD formuri VARCHAR(1000) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('ALTER TABLE isp_lanyard_domain_model_order DROP formuri');
    }
}
