<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Legt die Tabellen von ISP.Lanyard an: Events, Tage, Ticketarten,
 * Bestellungen und Positionen.
 */
final class Version20261026135828 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ISP.Lanyard: initial tables for events, days, ticket types and orders';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('CREATE TABLE isp_lanyard_domain_model_event (persistence_object_identifier VARCHAR(40) NOT NULL, title VARCHAR(255) NOT NULL, titleen VARCHAR(255) DEFAULT NULL, identifier VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, descriptionen LONGTEXT DEFAULT NULL, sortingindex INT NOT NULL, active TINYINT(1) NOT NULL, UNIQUE INDEX UNIQ_EE0F735E772E836A (identifier), PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE isp_lanyard_domain_model_eventday (persistence_object_identifier VARCHAR(40) NOT NULL, event VARCHAR(40) NOT NULL, date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', title VARCHAR(255) DEFAULT NULL, titleen VARCHAR(255) DEFAULT NULL, capacity INT NOT NULL, reservedseats INT NOT NULL, closed TINYINT(1) NOT NULL, INDEX IDX_172F1D603BAE0AA7 (event), UNIQUE INDEX UNIQ_172F1D603BAE0AA7AA9E377A (event, date), PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE isp_lanyard_domain_model_order (persistence_object_identifier VARCHAR(40) NOT NULL, event VARCHAR(40) NOT NULL, bookingnumber VARCHAR(255) DEFAULT NULL, status VARCHAR(20) NOT NULL, orderertype VARCHAR(20) NOT NULL, organisation VARCHAR(255) DEFAULT NULL, firstname VARCHAR(255) NOT NULL, lastname VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, phone VARCHAR(255) DEFAULT NULL, message LONGTEXT DEFAULT NULL, privacyconsent TINYINT(1) NOT NULL, privacyconsentat DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', language VARCHAR(10) NOT NULL, optintoken VARCHAR(255) DEFAULT NULL, optinsentat DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', optinconfirmedat DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', optinresendcount INT NOT NULL, rejectionreason LONGTEXT DEFAULT NULL, internalnote LONGTEXT DEFAULT NULL, paymentmethod VARCHAR(255) DEFAULT NULL, paymentamount INT DEFAULT NULL, paymentstatus VARCHAR(255) DEFAULT NULL, paymentdate DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', clientiphash VARCHAR(64) DEFAULT NULL, createdat DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updatedat DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_2088EA618DD40E7D (bookingnumber), INDEX IDX_2088EA613BAE0AA7 (event), INDEX status_idx (status), INDEX created_idx (createdat), PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE isp_lanyard_domain_model_orderitem (persistence_object_identifier VARCHAR(40) NOT NULL, `order` VARCHAR(40) NOT NULL, tickettype VARCHAR(40) DEFAULT NULL, tickettypetitle VARCHAR(255) NOT NULL, unitprice INT NOT NULL, quantity INT NOT NULL, INDEX IDX_F0C0D285F5299398 (`order`), INDEX IDX_F0C0D285C2CC95FF (tickettype), PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE isp_lanyard_domain_model_tickettype (persistence_object_identifier VARCHAR(40) NOT NULL, event VARCHAR(40) NOT NULL, title VARCHAR(255) NOT NULL, titleen VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, descriptionen LONGTEXT DEFAULT NULL, price INT NOT NULL, maxperorder INT NOT NULL, sortingindex INT NOT NULL, active TINYINT(1) NOT NULL, INDEX IDX_B52A4EC83BAE0AA7 (event), PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE isp_lanyard_domain_model_tickettype_covereddays_join (lanyard_tickettype VARCHAR(40) NOT NULL, lanyard_eventday VARCHAR(40) NOT NULL, INDEX IDX_65CE33E42BA97039 (lanyard_tickettype), INDEX IDX_65CE33E44501D6E (lanyard_eventday), PRIMARY KEY(lanyard_tickettype, lanyard_eventday)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_eventday ADD CONSTRAINT FK_172F1D603BAE0AA7 FOREIGN KEY (event) REFERENCES isp_lanyard_domain_model_event (persistence_object_identifier)');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_order ADD CONSTRAINT FK_2088EA613BAE0AA7 FOREIGN KEY (event) REFERENCES isp_lanyard_domain_model_event (persistence_object_identifier)');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_orderitem ADD CONSTRAINT FK_F0C0D285F5299398 FOREIGN KEY (`order`) REFERENCES isp_lanyard_domain_model_order (persistence_object_identifier)');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_orderitem ADD CONSTRAINT FK_F0C0D285C2CC95FF FOREIGN KEY (tickettype) REFERENCES isp_lanyard_domain_model_tickettype (persistence_object_identifier) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_tickettype ADD CONSTRAINT FK_B52A4EC83BAE0AA7 FOREIGN KEY (event) REFERENCES isp_lanyard_domain_model_event (persistence_object_identifier)');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_tickettype_covereddays_join ADD CONSTRAINT FK_65CE33E42BA97039 FOREIGN KEY (lanyard_tickettype) REFERENCES isp_lanyard_domain_model_tickettype (persistence_object_identifier)');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_tickettype_covereddays_join ADD CONSTRAINT FK_65CE33E44501D6E FOREIGN KEY (lanyard_eventday) REFERENCES isp_lanyard_domain_model_eventday (persistence_object_identifier)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('ALTER TABLE isp_lanyard_domain_model_eventday DROP FOREIGN KEY FK_172F1D603BAE0AA7');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_order DROP FOREIGN KEY FK_2088EA613BAE0AA7');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_tickettype DROP FOREIGN KEY FK_B52A4EC83BAE0AA7');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_tickettype_covereddays_join DROP FOREIGN KEY FK_65CE33E44501D6E');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_orderitem DROP FOREIGN KEY FK_F0C0D285F5299398');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_orderitem DROP FOREIGN KEY FK_F0C0D285C2CC95FF');
        $this->addSql('ALTER TABLE isp_lanyard_domain_model_tickettype_covereddays_join DROP FOREIGN KEY FK_65CE33E42BA97039');
        $this->addSql('DROP TABLE isp_lanyard_domain_model_event');
        $this->addSql('DROP TABLE isp_lanyard_domain_model_eventday');
        $this->addSql('DROP TABLE isp_lanyard_domain_model_order');
        $this->addSql('DROP TABLE isp_lanyard_domain_model_orderitem');
        $this->addSql('DROP TABLE isp_lanyard_domain_model_tickettype');
        $this->addSql('DROP TABLE isp_lanyard_domain_model_tickettype_covereddays_join');
    }
}
