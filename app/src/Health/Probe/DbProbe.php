<?php

namespace App\Health\Probe;

use App\Health\Probe\Abstracts\ProbeInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.health_probe')]
final class DbProbe implements ProbeInterface
{
    public function __construct(private readonly Connection $db) {}

    public function isHealthy(): bool
    {
        try { $this->db->executeQuery('SELECT 1')->fetchOne(); return true; }
        catch (\Throwable) { return false; }
    }

    public function name(): string
    {
        return 'database';
    }
}
