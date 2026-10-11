<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Contracts;

use ArtisanBuild\WakeContracts\IngestEnvelope;

interface Transport
{
    public function send(IngestEnvelope $envelope): bool;
}
