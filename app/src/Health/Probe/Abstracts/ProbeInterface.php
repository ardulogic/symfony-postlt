<?php

namespace App\Health\Probe\Abstracts;

interface ProbeInterface
{
    public function isHealthy(): bool;
    public function name(): string;
}
