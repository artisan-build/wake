<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeContracts\Support;

use ArtisanBuild\WakeContracts\Exceptions\ContractValidationException;
use DateTimeImmutable;

final class ContractFormat
{
    public static function ulid(string $value, string $path): void
    {
        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $value) !== 1) {
            throw ContractValidationException::at($path, 'must be a canonical uppercase ULID');
        }
    }

    public static function timestamp(string $value, string $path): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw ContractValidationException::at(
                $path,
                'must be RFC 3339 with exactly six fractional digits and an explicit offset',
            );
        }

        $format = str_ends_with($value, 'Z') ? '!Y-m-d\TH:i:s.u\Z' : '!Y-m-d\TH:i:s.uP';
        $parsed = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw ContractValidationException::at($path, 'must be a real calendar timestamp');
        }
    }

    public static function idempotencyKey(string $value, string $path): void
    {
        ContractInput::boundedString($value, $path, 128);

        if (preg_match('/^[A-Za-z0-9._:-]+$/D', $value) !== 1) {
            throw ContractValidationException::at($path, 'may contain only ASCII letters, digits, dot, underscore, colon, and hyphen');
        }
    }
}
