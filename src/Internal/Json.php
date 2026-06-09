<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Internal;

use Octoverse\Octonomy\Exception\TransportException;

/**
 * Json holds small, defensive helpers for reading values out of a decoded JSON
 * response body. They never throw on a missing/mismatched field — they return a
 * safe zero value — so model hydration stays total.
 *
 * @internal
 */
final class Json
{
    /**
     * Decode a JSON object body into a string-keyed array.
     *
     * @return array<string, mixed>
     *
     * @throws TransportException when the payload is not valid JSON
     */
    public static function decode(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TransportException('Failed to decode Octonomy response: ' . $e->getMessage(), 0, $e);
        }

        return is_array($data) ? self::stringKeyed($data) : [];
    }

    /**
     * Decode leniently, returning an empty array instead of throwing when the
     * body is missing or not valid JSON. Used for error responses, where a
     * non-JSON body (e.g. a gateway 502) should fall back gracefully.
     *
     * @return array<string, mixed>
     */
    public static function tryDecode(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        /** @var mixed $data */
        $data = json_decode($raw, true);

        return is_array($data) ? self::stringKeyed($data) : [];
    }

    /**
     * @param array<string, mixed> $d
     */
    public static function string(array $d, string $key): string
    {
        $v = $d[$key] ?? null;

        return is_string($v) ? $v : '';
    }

    /**
     * @param array<string, mixed> $d
     */
    public static function nullableString(array $d, string $key): ?string
    {
        $v = $d[$key] ?? null;

        return is_string($v) ? $v : null;
    }

    /**
     * @param array<string, mixed> $d
     */
    public static function bool(array $d, string $key): bool
    {
        $v = $d[$key] ?? null;

        return is_bool($v) ? $v : false;
    }

    /**
     * @param array<string, mixed> $d
     */
    public static function int(array $d, string $key): int
    {
        $v = $d[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }

        return is_numeric($v) ? (int) $v : 0;
    }

    /**
     * Read a nullable JSON object/array field (e.g. `metadata`).
     *
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>|null
     */
    public static function nullableObject(array $d, string $key): ?array
    {
        $v = $d[$key] ?? null;

        return is_array($v) ? self::stringKeyed($v) : null;
    }

    /**
     * Read a JSON object field, defaulting to an empty array.
     *
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    public static function object(array $d, string $key): array
    {
        return self::nullableObject($d, $key) ?? [];
    }

    /**
     * Read a JSON array-of-objects field as a list of string-keyed arrays.
     *
     * @param array<string, mixed> $d
     *
     * @return list<array<string, mixed>>
     */
    public static function objectList(array $d, string $key): array
    {
        $v = $d[$key] ?? null;
        if (!is_array($v)) {
            return [];
        }

        $out = [];
        foreach ($v as $row) {
            if (is_array($row)) {
                $out[] = self::stringKeyed($row);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $d
     */
    public static function dateTime(array $d, string $key): \DateTimeImmutable
    {
        $v = $d[$key] ?? null;
        if (is_string($v) && $v !== '') {
            try {
                return new \DateTimeImmutable($v);
            } catch (\Exception) {
                // fall through to epoch
            }
        }

        return new \DateTimeImmutable('@0');
    }

    /**
     * Normalize an arbitrary array to a string-keyed array so downstream type
     * hints (`array<string, mixed>`) hold.
     *
     * @param array<array-key, mixed> $a
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $a): array
    {
        $out = [];
        foreach ($a as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
