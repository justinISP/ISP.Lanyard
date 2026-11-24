<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ON DELETE CASCADE für die Join-Tabelle TicketType <-> EventDay.
 * Ohne Cascade scheitert das Löschen eines Events am Fremdschlüssel.
 */
final class Version20261026144305 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ISP.Lanyard: cascade deletes on tickettype_covereddays_join';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('ALTER TABLE isp_lanyard_domain_model_tickettype_covereddays_join DROP FOREIGN KEY FK_65CE33E42BA97039');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_tickettype_covereddays_join DROP FOREIGN KEY FK_65CE33E44501D6E');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_tickettype_covereddays_join ADD CONSTRAINT FK_65CE33E42BA97039 FOREIGN KEY (lanyard_tickettype) REFERENCES isp_lanyard_domain_model_tickettype (persistence_object_identifier) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_tickettype_covereddays_join ADD CONSTRAINT FK_65CE33E44501D6E FOREIGN KEY (lanyard_eventday) REFERENCES isp_lanyard_domain_model_eventday (persistence_object_identifier) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('ALTER TABLE isp_lanyard_domain_model_tickettype_covereddays_join DROP FOREIGN KEY FK_65CE33E42BA97039');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_tickettype_covereddays_join DROP FOREIGN KEY FK_65CE33E44501D6E');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_tickettype_covereddays_join ADD CONSTRAINT FK_65CE33E42BA97039 FOREIGN KEY (lanyard_tickettype) REFERENCES isp_lanyard_domain_model_tickettype (persistence_object_identifier)');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_tickettype_covereddays_join ADD CONSTRAINT FK_65CE33E44501D6E FOREIGN KEY (lanyard_eventday) REFERENCES isp_lanyard_domain_model_eventday (persistence_object_identifier)');
    }
}
