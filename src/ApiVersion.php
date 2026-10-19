<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

/**
 * ApiVersion names the two Octonomy REST surfaces, /api/v1 and /api/v2. They
 * carry the same paths but not the same fields: v2 adds the namespace_type and
 * namespace_id pair to every namespaced resource, and the server strips that
 * pair from every v1 response (octonomy/core/serializers.py upstream). Model
 * hydration therefore needs to know which surface a response came from.
 *
 * These are string constants, not an enum, because the 1.x line is downgraded
 * to PHP 7.4 and Rector cannot carry an enum there (issue #5).
 */
final class ApiVersion
{
    public const V1 = 'v1';

    public const V2 = 'v2';

    private function __construct() {}
}
