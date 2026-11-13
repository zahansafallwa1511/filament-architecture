<?php

namespace App\Domain\Shared;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
