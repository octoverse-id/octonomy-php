<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Internal;

/**
 * ScopeQuery builds the query string that scopes a single-resource call: the
 * application_id and, on a read, include_global parameters that list calls
 * take from their *ListParams instead. A null value is omitted, and a boolean is
 * sent as "true"/"false", as the list params do.
 *
 * On v2 a namespaced call must name its application (namespace isolation sits
 * below application on the server, which answers 403 without one), and reads a
 * global row from inside a namespace only with include_global.
 *
 * @internal
 */
final class ScopeQuery
{
    /**
     * @return array<string, string>
     */
    public static function of(?string $applicationId, ?bool $includeGlobal = null): array
    {
        $query = [];
        if ($applicationId !== null) {
            $query['application_id'] = $applicationId;
        }
        if ($includeGlobal !== null) {
            $query['include_global'] = $includeGlobal ? 'true' : 'false';
        }

        return $query;
    }
}
