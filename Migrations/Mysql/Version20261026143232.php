<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Benennt die Spalte "order" in OrderItem um – reserviertes SQL-Wort,
 * das Doctrine beim INSERT nicht maskiert.
 */
final class Version20261026143232 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ISP.Lanyard: rename orderitem.order to lanyard_order';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('ALTER TABLE isp_lanyard_domain_model_orderitem DROP FOREIGN KEY FK_F0C0D285F5299398');
        $this->addSql('DROP INDEX IDX_F0C0D285F5299398 ON isp_lanyard_domain_model_orderitem');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_orderitem CHANGE `order` lanyard_order VARCHAR(40) NOT NULL');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_orderitem ADD CONSTRAINT FK_F0C0D2853473A377 FOREIGN KEY (lanyard_order) REFERENCES isp_lanyard_domain_model_order (persistence_object_identifier)');
        $this->addSql('CREATE INDEX IDX_F0C0D2853473A377 ON isp_lanyard_domain_model_orderitem (lanyard_order)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('ALTER TABLE isp_lanyard_domain_model_orderitem DROP FOREIGN KEY FK_F0C0D2853473A377');
        $this->addSql('DROP INDEX IDX_F0C0D2853473A377 ON isp_lanyard_domain_model_orderitem');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_orderitem CHANGE lanyard_order `order` VARCHAR(40) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_orderitem ADD CONSTRAINT FK_F0C0D285F5299398 FOREIGN KEY (`order`) REFERENCES isp_lanyard_domain_model_order (persistence_object_identifier)');
        $this->addSql('CREATE INDEX IDX_F0C0D285F5299398 ON isp_lanyard_domain_model_orderitem (`order`)');
    }
}
