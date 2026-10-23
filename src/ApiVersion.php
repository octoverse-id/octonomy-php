<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

use Octoverse\Octonomy\Exception\ConfigurationException;

/**
 * ApiVersion names the two Octonomy REST surfaces, /api/v1 and /api/v2. They
 * carry the same paths but not the same fields: v2 adds the namespace_type and
 * namespace_id pair to every namespaced resource, and the server strips that
 * pair from every v1 response (octonomy/core/serializers.py upstream). Model
 * hydration therefore needs to know which surface a response came from.
 *
 * A Client calls V2 unless constructed with 'api_version' => ApiVersion::V1,
 * which an Octonomy server older than 2.0.0 needs. Only v2 has the namespace
 * axis: NamespaceScope and include_global are refused on a v1 client.
 *
 * These are string constants, not an enum, because the 1.x line is downgraded
 * to PHP 7.4 and Rector cannot carry an enum there (issue #5), so check()
 * does what an enum type would.
 */
final class ApiVersion
{
    public const V1 = 'v1';

    public const V2 = 'v2';

    private function __construct() {}

    /**
     * Return $apiVersion if it is V1 or V2. Nothing else may reach a URL or a
     * hydrator, and with no enum type to refuse "v3" or "V2", this is the check.
     *
     * @throws ConfigurationException when $apiVersion is neither
     */
    public static function check(string $apiVersion): string
    {
        if ($apiVersion !== self::V1 && $apiVersion !== self::V2) {
            throw new ConfigurationException(sprintf(
                'octonomy: api_version must be "%s" or "%s", got "%s". Fix: use "v2" (ApiVersion::V2, the default,'
                . ' for Octonomy server 2.0.0 or newer) or "v1" (ApiVersion::V1, for an older server); both are'
                . ' lowercase.',
                self::V1,
                self::V2,
                $apiVersion,
            ));
        }

        return $apiVersion;
    }
}
