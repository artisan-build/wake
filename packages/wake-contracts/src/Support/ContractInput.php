<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeContracts\Support;

use ArtisanBuild\WakeContracts\Exceptions\ContractValidationException;
use JsonException;
use stdClass;

final class ContractInput
{
    /**
     * @param  array<array-key, mixed>  $input
     * @param  list<string>  $required
     * @param  list<string>  $optional
     */
    public static function assertShape(array $input, array $required, array $optional = [], string $path = '$'): void
    {
        foreach ($required as $field) {
            if (! array_key_exists($field, $input)) {
                throw ContractValidationException::at(self::path($path, $field), 'is required');
            }
        }

        $allowed = array_flip([...$required, ...$optional]);

        foreach (array_keys($input) as $field) {
            if (! array_key_exists($field, $allowed)) {
                $name = (string) $field;

                throw ContractValidationException::at(self::path($path, $name), 'is not a recognized contract field');
            }
        }
    }

    /** @param array<string, mixed> $input */
    public static function string(array $input, string $field, string $path = '$'): string
    {
        $value = $input[$field] ?? null;

        if (! is_string($value)) {
            throw ContractValidationException::at(self::path($path, $field), 'must be a string');
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    public static function nullableString(array $input, string $field, string $path = '$'): ?string
    {
        $value = $input[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw ContractValidationException::at(self::path($path, $field), 'must be a string or null');
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    public static function integer(array $input, string $field, string $path = '$'): int
    {
        $value = $input[$field] ?? null;

        if (! is_int($value)) {
            throw ContractValidationException::at(self::path($path, $field), 'must be an integer');
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    public static function nullableInteger(array $input, string $field, string $path = '$'): ?int
    {
        $value = $input[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw ContractValidationException::at(self::path($path, $field), 'must be an integer or null');
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    public static function boolean(array $input, string $field, string $path = '$'): bool
    {
        $value = $input[$field] ?? null;

        if (! is_bool($value)) {
            throw ContractValidationException::at(self::path($path, $field), 'must be a boolean');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<mixed>
     */
    public static function array(array $input, string $field, string $path = '$'): array
    {
        $value = $input[$field] ?? null;

        if (! is_array($value)) {
            throw ContractValidationException::at(self::path($path, $field), 'must be an array');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function objectArray(array $input, string $field, string $path = '$'): array
    {
        $value = $input[$field] ?? null;

        if ($value instanceof stdClass) {
            /** @var array<string, mixed> */
            return (array) $value;
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw ContractValidationException::at(self::path($path, $field), 'must be a JSON object');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return array<string, mixed> */
    public static function decodeObject(string $json): array
    {
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw ContractValidationException::at('$', 'must be valid JSON: '.$exception->getMessage());
        }

        if (! is_array($value) || array_is_list($value)) {
            throw ContractValidationException::at('$', 'must be a JSON object');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    public static function boundedString(
        string $value,
        string $path,
        int $maximumBytes,
        bool $allowEmpty = false,
    ): void {
        if (! $allowEmpty && $value === '') {
            throw ContractValidationException::at($path, 'must not be empty');
        }

        if (strlen($value) > $maximumBytes) {
            throw ContractValidationException::at($path, "must be at most {$maximumBytes} bytes");
        }

        if (preg_match('//u', $value) !== 1) {
            throw ContractValidationException::at($path, 'must be valid UTF-8');
        }
    }

    /** @param array<mixed> $value */
    public static function jsonObject(array $value, string $path, int $maximumBytes): void
    {
        if ($value !== [] && array_is_list($value)) {
            throw ContractValidationException::at($path, 'must be a JSON object');
        }

        self::assertJsonValue($value, $path);

        try {
            $bytes = strlen(CanonicalJson::encode($value));
        } catch (JsonException $exception) {
            throw ContractValidationException::at($path, 'must be JSON encodable: '.$exception->getMessage());
        }

        if ($bytes > $maximumBytes) {
            throw ContractValidationException::at($path, "must encode to at most {$maximumBytes} bytes");
        }
    }

    public static function path(string $parent, string $field): string
    {
        return $parent === '$' ? '$.'.$field : $parent.'.'.$field;
    }

    private static function assertJsonValue(mixed $value, string $path): void
    {
        if (is_null($value) || is_string($value) || is_int($value) || is_bool($value)) {
            return;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw ContractValidationException::at($path, 'must not contain a non-finite number');
            }

            return;
        }

        if ($value instanceof stdClass) {
            foreach ((array) $value as $key => $item) {
                self::assertJsonValue($item, $path.'['.$key.']');
            }

            return;
        }

        if (! is_array($value)) {
            throw ContractValidationException::at($path, 'must contain only JSON values');
        }

        foreach ($value as $key => $item) {
            self::assertJsonValue($item, $path.'['.(string) $key.']');
        }
    }
}
