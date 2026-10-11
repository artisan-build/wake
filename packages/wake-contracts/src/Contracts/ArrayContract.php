<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeContracts\Contracts;

use JsonSerializable;

interface ArrayContract extends JsonSerializable
{
    /** @return array<string, mixed> */
    public function toArray(): array;

    public function toJson(): string;
}
