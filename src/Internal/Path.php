<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Internal;

/**
 * Path joins the segments of a request path, below the api_version prefix,
 * percent-encoding each one, so a value cannot add a segment of its own: an id
 * "a/b" is sent as the single segment a%2Fb. Every service builds its paths
 * here, so the encoding lives in one place (#23).
 *
 * A segment that is empty, "." or ".." is refused before anything is sent,
 * since no encoding can carry it. An empty one leaves a path that names no
 * single resource. A dot segment is removed by the URL resolution an HTTP
 * client or a proxy applies (RFC 3986, section 5.2.4): libcurl, which Guzzle
 * sends through, turns resources/product/../tags into resources/tags, another
 * endpoint.
 *
 * @internal
 */
final class Path
{
    private function __construct() {}

    /**
     * The segments, each percent-encoded (RFC 3986), joined by "/".
     *
     * @throws \InvalidArgumentException when a segment is empty, "." or ".."; nothing is sent
     */
    public static function of(string ...$segments): string
    {
        $encoded = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                // Scrubbed like an OctonomyException: the service frames below
                // this one hold the request body and the RequestOptions.
                throw Redaction::scrub(new \InvalidArgumentException(ErrorMessage::unaddressable($segment)));
            }
            $encoded[] = rawurlencode($segment);
        }

        return implode('/', $encoded);
    }
}
