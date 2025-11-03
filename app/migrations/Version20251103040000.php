<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251103040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reallocation_locked flag to stock_reservations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE stock_reservations ADD reallocation_locked BOOLEAN DEFAULT FALSE NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE stock_reservations DROP COLUMN reallocation_locked");
    }
}


