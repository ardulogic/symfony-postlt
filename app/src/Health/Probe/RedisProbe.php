<?php
// src/Health/Probe/RedisProbe.php
namespace App\Health\Probe;

use App\Health\Probe\Abstracts\ProbeInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.health_probe')]
final class RedisProbe implements ProbeInterface
{
    public function __construct(private readonly \Redis $redis)
    {
    }

    public function isHealthy(): bool
    {
        try {
            // Redis returns same message that is being sent
            return $this->redis->ping('ping') === 'ping';
        } catch (\Throwable) {
            return false;
        }
    }

    public function name(): string
    {
        return 'redis';
    }
}
