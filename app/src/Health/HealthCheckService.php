<?php

namespace App\Health;

use App\Health\Probe\Abstracts\ProbeInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class HealthCheckService
{
    /** @param iterable<ProbeInterface> $probes */
    public function __construct(
        #[AutowireIterator('app.health_probe')]
        private readonly iterable $probes,
    ) {}

    /** @return array{ok: bool, results: array<bool, array{ok:bool}>} */
    public function probeAll(): array
    {
        $systemHealthy = true;
        $results = [];

        foreach ($this->probes as $probe) {
            $probeHealthy = $probe->isHealthy();
            $results[$probe->name()] = ['ok' => $probeHealthy];

            $systemHealthy = $systemHealthy && $probeHealthy;
        }

        return ['ok' => $systemHealthy, 'results' => $results];
    }
}
