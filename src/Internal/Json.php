<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Internal;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Exception\MalformedResponseException;

/**
 * Json decodes response bodies and reads fields out of them strictly.
 *
 * Every reader requires its key to be present and its value to have the JSON
 * type the model declares, and throws MalformedResponseException naming the
 * field otherwise. Nothing is defaulted and nothing is coerced: a zero value or
 * a "5" read as 5 would hide contract drift, which is how six of ten methods
 * once returned empty models without an error (#6, #14). Nullability governs
 * the value only. A nullable field's key must still be present, because the
 * server always emits it (octonomy/tags/serializers.py upstream); the readers
 * with "OrAbsent" in their name are the named exceptions to that rule.
 *
 * The readers take an object's members as members() returns them: nested JSON
 * objects are still \stdClass, so a reader can tell an object from a list,
 * which array decoding cannot.
 *
 * @internal
 */
final class Json
{
    /**
     * An RFC 3339 timestamp, the form DRF renders a DateTimeField in. Validated
     * before parsing because \DateTimeImmutable also accepts "now", "yesterday"
     * and "+1 day", which are no timestamp a server sends.
     */
    private const TIMESTAMP = '/^(\d{4})-(\d{2})-(\d{2})T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/';

    /**
     * Decode a response body, keeping JSON objects as \stdClass. Decoding to
     * arrays would make {} and [] identical, and would turn {"0": ...} into what
     * looks like a list, so envelope checks must run on this form. Returns null
     * for an empty body.
     *
     * The caller turns a failure into a TransportException, since only it knows
     * the request the body answered.
     *
     * @throws \JsonException when the payload is not valid JSON
     */
    public static function decode(string $raw): mixed
    {
        if ($raw === '') {
            return null;
        }

        return json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The members of a JSON object from decode(), as the string-keyed array the
     * readers take. Only the top level is converted: a nested object stays
     * \stdClass and a nested list stays an array, so the reader for that field
     * can still tell which one the server sent.
     *
     * @return array<string, mixed>
     */
    public static function members(\stdClass $object): array
    {
        $out = [];
        foreach (get_object_vars($object) as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * Run a model's reads, and mark any field they reject with the api_version
     * the model read the body as, which the readers themselves do not know.
     *
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T
     *
     * @throws MalformedResponseException when a read rejects a field
     */
    public static function readAs(string $apiVersion, \Closure $read): mixed
    {
        try {
            return $read();
        } catch (MalformedResponseException $e) {
            throw $e->readAs($apiVersion);
        }
    }

    /**
     * Decode leniently, returning an empty array instead of throwing when the
     * body is missing or not valid JSON. Used for error responses only, where a
     * non-JSON body (e.g. a gateway 502) must still become an ApiException
     * chosen by its status.
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
     *
     * @throws MalformedResponseException when the key is absent or not a string
     */
    public static function string(array $d, string $key): string
    {
        $v = self::present($d, $key);
        if (!is_string($v)) {
            throw self::wrongType($key, 'a string', $v);
        }

        return $v;
    }

    /**
     * @param array<string, mixed> $d
     *
     * @throws MalformedResponseException when the key is absent, or neither a string nor null
     */
    public static function nullableString(array $d, string $key): ?string
    {
        $v = self::present($d, $key);
        if ($v !== null && !is_string($v)) {
            throw self::wrongType($key, 'a string or null', $v);
        }

        return $v;
    }

    /**
     * Read a nullable string the server omits, rather than sends as null, when
     * it has no value. An absent key yields null; a present one must still be a
     * string or null. Only for a field the server is known to omit, with the
     * reason at the call site: everywhere else an absent key is drift.
     *
     * @param array<string, mixed> $d
     *
     * @throws MalformedResponseException when the key is present but neither a string nor null
     */
    public static function nullableStringOrAbsent(array $d, string $key): ?string
    {
        return array_key_exists($key, $d) ? self::nullableString($d, $key) : null;
    }

    /**
     * @param array<string, mixed> $d
     *
     * @throws MalformedResponseException when the key is absent or not a boolean
     */
    public static function bool(array $d, string $key): bool
    {
        $v = self::present($d, $key);
        if (!is_bool($v)) {
            throw self::wrongType($key, 'a boolean', $v);
        }

        return $v;
    }

    /**
     * @param array<string, mixed> $d
     *
     * @throws MalformedResponseException when the key is absent or not an integer
     */
    public static function int(array $d, string $key): int
    {
        $v = self::present($d, $key);
        if (!is_int($v)) {
            throw self::wrongType($key, 'an integer', $v);
        }

        return $v;
    }

    /**
     * Read a nullable JSON object field of caller-written JSON (e.g.
     * `metadata`). A JSON list is rejected, which is why the members must still
     * hold the \stdClass decode() produced.
     *
     * Only the top level becomes an array, under PHP's array keys ("0" becomes
     * 0). Below it every object stays the \stdClass decode() made and every list
     * stays an array, because an array cannot say which of the two it was: a
     * nested {} or {"0": "a"} read as an array would go back out as the list []
     * or ["a"] (#48). The top level needs no such care, since the field is an
     * object by contract and the request DTOs always send it as one.
     *
     * @param array<string, mixed> $d
     *
     * @return array<array-key, mixed>|null
     *
     * @throws MalformedResponseException when the key is absent, or neither an object nor null
     */
    public static function nullableObject(array $d, string $key): ?array
    {
        $v = self::present($d, $key);
        if ($v === null) {
            return null;
        }
        if (!$v instanceof \stdClass) {
            throw self::wrongType($key, 'an object or null', $v);
        }

        return get_object_vars($v);
    }

    /**
     * Read a resource nested in another, such as a resolution's tag, by running
     * $hydrate over the nested object's members. A field the nested model
     * rejects is reported at its path through this field ("tag.slug").
     *
     * @template T
     *
     * @param array<string, mixed>                       $d
     * @param \Closure(array<string, mixed>, string): T $hydrate a model's fromArray(...)
     * @param string                                     $apiVersion the api_version the body is read as, which
     *                                                               the nested model's field set depends on too
     *
     * @return T
     *
     * @throws MalformedResponseException when the key is absent, its value is not an object, or the object
     *                                    fails to hydrate
     */
    public static function resource(array $d, string $key, \Closure $hydrate, string $apiVersion): mixed
    {
        $v = self::present($d, $key);
        if (!$v instanceof \stdClass) {
            throw self::wrongType($key, 'an object', $v);
        }

        return self::hydrateWithin($key, $hydrate, $v, $apiVersion);
    }

    /**
     * resource() for a nested resource that may be null, such as a
     * resolution's matched alias when the slug matched a tag. The key must
     * still be present.
     *
     * @template T
     *
     * @param array<string, mixed>                       $d
     * @param \Closure(array<string, mixed>, string): T $hydrate
     *
     * @return T|null
     *
     * @throws MalformedResponseException when the key is absent, its value is neither an object nor null,
     *                                    or the object fails to hydrate
     */
    public static function nullableResource(array $d, string $key, \Closure $hydrate, string $apiVersion): mixed
    {
        $v = self::present($d, $key);
        if ($v === null) {
            return null;
        }
        if (!$v instanceof \stdClass) {
            throw self::wrongType($key, 'an object or null', $v);
        }

        return self::hydrateWithin($key, $hydrate, $v, $apiVersion);
    }

    /**
     * Read a JSON list of nested resources, such as a bulk assignment's
     * assignments, hydrating each with $hydrate. A field one of them rejects is
     * reported at its place in the list ("assignments[2].tag_id").
     *
     * @template T
     *
     * @param array<string, mixed>                       $d
     * @param \Closure(array<string, mixed>, string): T $hydrate
     *
     * @return list<T>
     *
     * @throws MalformedResponseException when the key is absent, its value is not a list, an item is not
     *                                    an object, or an item fails to hydrate
     */
    public static function resources(array $d, string $key, \Closure $hydrate, string $apiVersion): array
    {
        $v = self::present($d, $key);
        // decode() makes every JSON object a \stdClass, so an array is a list.
        if (!is_array($v)) {
            throw self::wrongType($key, 'a list', $v);
        }
        $out = [];
        foreach (array_values($v) as $i => $item) {
            if (!$item instanceof \stdClass) {
                throw self::wrongType("{$key}[{$i}]", 'an object', $item);
            }
            $out[] = self::hydrateWithin("{$key}[{$i}]", $hydrate, $item, $apiVersion);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $d
     *
     * @throws MalformedResponseException when the key is absent or not an RFC 3339 timestamp
     */
    public static function dateTime(array $d, string $key): \DateTimeImmutable
    {
        $v = self::present($d, $key);
        if (!is_string($v)) {
            throw self::wrongType($key, 'an RFC 3339 timestamp', $v);
        }
        if (preg_match(self::TIMESTAMP, $v, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new MalformedResponseException('must be an RFC 3339 timestamp', $key, null, null, null, $v);
        }

        return new \DateTimeImmutable($v);
    }

    /**
     * Read one half of the namespace pair (namespace_type, namespace_id), the
     * one place the field set depends on the api_version. v2 declares the pair
     * on every namespaced resource and always emits it, null for a global row;
     * the server strips it from every v1 response (octonomy/core/serializers.py
     * upstream). So on v2 the key must be present, and on v1 it must be absent
     * and reads as null, since v1 only ever sees the global partition.
     *
     * @param array<string, mixed> $d
     *
     * @throws MalformedResponseException when v2 lacks the key, v1 carries it, or the value is
     *                                    neither a string nor null
     */
    public static function namespaceField(array $d, string $key, string $apiVersion): ?string
    {
        if ($apiVersion === ApiVersion::V2) {
            return self::nullableString($d, $key);
        }
        if ($apiVersion !== ApiVersion::V1) {
            throw new \InvalidArgumentException(sprintf('octonomy: unknown api_version "%s"; expected "v1" or "v2"', $apiVersion));
        }
        if (array_key_exists($key, $d)) {
            throw new MalformedResponseException('is not part of an api_version v1 response', $key);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $d
     *
     * @throws MalformedResponseException when the key is absent
     */
    private static function present(array $d, string $key): mixed
    {
        if (!array_key_exists($key, $d)) {
            throw new MalformedResponseException('is missing', $key);
        }

        return $d[$key];
    }

    /**
     * Run a nested model's $hydrate over $object, placing any field it rejects
     * at $at, the object's place in the model being read.
     *
     * @template T
     *
     * @param \Closure(array<string, mixed>, string): T $hydrate
     *
     * @return T
     */
    private static function hydrateWithin(string $at, \Closure $hydrate, \stdClass $object, string $apiVersion): mixed
    {
        try {
            return $hydrate(self::members($object), $apiVersion);
        } catch (MalformedResponseException $e) {
            throw $e->within($at);
        }
    }

    private static function wrongType(string $key, string $expected, mixed $actual): MalformedResponseException
    {
        $type = match (true) {
            $actual === null => 'null',
            is_bool($actual) => 'a boolean',
            is_int($actual) => 'an integer',
            is_float($actual) => 'a number',
            is_string($actual) => 'a string',
            $actual instanceof \stdClass => 'an object',
            default => 'an array',
        };

        return new MalformedResponseException("must be {$expected}, got {$type}", $key);
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
