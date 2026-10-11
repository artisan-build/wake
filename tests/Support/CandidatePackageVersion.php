<?php

declare(strict_types=1);

namespace Tests\Support;

use Composer\Semver\Semver;
use UnexpectedValueException;

final class CandidatePackageVersion
{
    private const string PACKAGE = 'artisan-build/built-for-cloud';

    /** @param array<string, mixed> $composer */
    public static function fromStarterComposer(array $composer): string
    {
        $require = $composer['require'] ?? null;
        $constraint = is_array($require) ? ($require[self::PACKAGE] ?? null) : null;

        if (! is_string($constraint)
            || preg_match('/^\^(\d+)(?:\.(\d+))?(?:\.(\d+))?$/D', $constraint, $matches) !== 1) {
            throw new UnexpectedValueException('The starter package constraint must be a numeric caret constraint.');
        }

        $version = sprintf(
            '%d.%d.%d',
            (int) $matches[1],
            (int) ($matches[2] ?? 0),
            (int) ($matches[3] ?? 0),
        );

        if (! Semver::satisfies($version, $constraint)) {
            throw new UnexpectedValueException('The derived candidate version does not satisfy the starter package constraint.');
        }

        return $version;
    }
}
