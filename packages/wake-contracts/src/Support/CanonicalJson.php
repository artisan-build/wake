<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeContracts\Support;

use JsonException;
use stdClass;

final class CanonicalJson
{
    /**
     * @param  array<mixed>  $value
     *
     * @throws JsonException
     */
    public static function encode(array $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            /** @var array<string, mixed> $properties */
            $properties = (array) $value;
            ksort($properties, SORT_STRING);

            $normalized = new stdClass;
            foreach ($properties as $key => $item) {
                $normalized->{$key} = self::normalize($item);
            }

            return $normalized;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}
