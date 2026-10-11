<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeContracts\Exceptions;

use InvalidArgumentException;

final class ContractValidationException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $path,
        string $message,
    ) {
        parent::__construct($path.': '.$message);
    }

    public static function at(string $path, string $message): self
    {
        return new self($path, $message);
    }

    public static function unsupportedEnvelopeVersion(int $received, int $supportedMaximum): self
    {
        return new self(
            'envelope_version',
            "received envelope version {$received}; this server supports versions through {$supportedMaximum}",
        );
    }
}
