<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251031151515 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE order_lines (id UUID NOT NULL, product_sku VARCHAR(64) NOT NULL, qty_ordered INT NOT NULL, qty_reserved INT NOT NULL, qty_shipped INT NOT NULL, status VARCHAR(32) NOT NULL, order_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_CC9FF86B8D9F6D38 ON order_lines (order_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_orderline_number_sku ON order_lines (order_id, product_sku)');
        $this->addSql('CREATE TABLE orders (id UUID NOT NULL, number VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E52FFDEE96901F54 ON orders (number)');
        $this->addSql('ALTER TABLE order_lines ADD CONSTRAINT FK_CC9FF86B8D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE order_lines DROP CONSTRAINT FK_CC9FF86B8D9F6D38');
        $this->addSql('DROP TABLE order_lines');
        $this->addSql('DROP TABLE orders');
    }
}
