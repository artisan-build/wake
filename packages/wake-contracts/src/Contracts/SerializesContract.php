<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeContracts\Contracts;

use ArtisanBuild\WakeContracts\Support\CanonicalJson;

trait SerializesContract
{
    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }
}
