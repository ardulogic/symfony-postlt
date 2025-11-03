<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251103032545 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE stock_reservation_lines (id UUID NOT NULL, product_sku VARCHAR(64) NOT NULL, qty_ordered INT NOT NULL, qty_reserved INT NOT NULL, qty_shipped INT NOT NULL, status VARCHAR(32) NOT NULL, stock_reservation_id UUID NOT NULL, warehouse_id INT DEFAULT NULL, stock_item_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7CB598841FD12EAC ON stock_reservation_lines (stock_reservation_id)');
        $this->addSql('CREATE INDEX IDX_7CB598845080ECDE ON stock_reservation_lines (warehouse_id)');
        $this->addSql('CREATE INDEX IDX_7CB59884BC942FD ON stock_reservation_lines (stock_item_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_line_res_wh_sku ON stock_reservation_lines (stock_reservation_id, warehouse_id, product_sku)');
        $this->addSql('CREATE TABLE stock_reservations (id UUID NOT NULL, number VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2126982A96901F54 ON stock_reservations (number)');
        $this->addSql('ALTER TABLE stock_reservation_lines ADD CONSTRAINT FK_7CB598841FD12EAC FOREIGN KEY (stock_reservation_id) REFERENCES stock_reservations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE stock_reservation_lines ADD CONSTRAINT FK_7CB598845080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE stock_reservation_lines ADD CONSTRAINT FK_7CB59884BC942FD FOREIGN KEY (stock_item_id) REFERENCES stock_items (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE stock_reservation_lines DROP CONSTRAINT FK_7CB598841FD12EAC');
        $this->addSql('ALTER TABLE stock_reservation_lines DROP CONSTRAINT FK_7CB598845080ECDE');
        $this->addSql('ALTER TABLE stock_reservation_lines DROP CONSTRAINT FK_7CB59884BC942FD');
        $this->addSql('DROP TABLE stock_reservation_lines');
        $this->addSql('DROP TABLE stock_reservations');
    }
}
