<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Internal;

/**
 * Path joins the segments of a request path, below the api_version prefix,
 * percent-encoding each one, so a value cannot add a segment of its own: an id
 * "a/b" is sent as the single segment a%2Fb. Every service builds its paths
 * here, so the encoding lives in one place (#23).
 *
 * A segment that is exactly "." or ".." is sent as %2E or %2E%2E. Both are
 * valid resource types and ids on the server, which refuses only a blank one,
 * but rawurlencode() leaves dots alone, and a literal dot segment is removed by
 * the URL resolution an HTTP client applies (RFC 3986, section 5.2.4): libcurl,
 * which Guzzle sends through, turns resources/product/../tags into
 * resources/tags, another endpoint. Encoded, it reaches the server as the one
 * segment it is, which the server decodes back to "." or "..". A proxy in front
 * of the server that decodes and normalizes the path before forwarding it can
 * still remove it.
 *
 * An empty segment is refused before anything is sent: no encoding carries
 * one, and the path it leaves names no single resource.
 *
 * @internal
 */
final class Path
{
    private function __construct() {}

    /**
     * The segments, each percent-encoded (RFC 3986), joined by "/".
     *
     * @throws \InvalidArgumentException when a segment is empty; nothing is sent
     */
    public static function of(string ...$segments): string
    {
        $encoded = [];
        foreach ($segments as $segment) {
            if ($segment === '') {
                // Scrubbed like an OctonomyException: the service frames below
                // this one hold the request body and the RequestOptions.
                throw Redaction::scrub(new \InvalidArgumentException(ErrorMessage::emptyPathSegment()));
            }
            $encoded[] = match ($segment) {
                '.' => '%2E',
                '..' => '%2E%2E',
                default => rawurlencode($segment),
            };
        }

        return implode('/', $encoded);
    }
}
