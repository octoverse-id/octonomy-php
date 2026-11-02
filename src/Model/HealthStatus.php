<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Model;

use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Internal\Json;

/**
 * HealthStatus is a health probe's answer (#24): whether the server passed it,
 * and the word it answered with.
 *
 * $ok comes from the HTTP status, not from the word: a 2xx passed, and the
 * 503 the server answers /health/ready with when it cannot reach its database
 * did not. So a word a newer server adds reaches $status verbatim instead of
 * being refused, and nothing here compares it. $status is the body's "status"
 * member, as sent: STATUS_OK or STATUS_UNAVAILABLE at server 3.2.1.
 *
 * The server sends the probes' body bare, {"status": "ok"}, with no "data"
 * envelope, which is why HealthTransport reads it rather than Transport.
 */
final class HealthStatus
{
    /** The word a passing probe answers with. */
    public const STATUS_OK = 'ok';

    /** The word /health/ready answers with, alongside a 503, when the database is unreachable. */
    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * A probe's answer as fromArray() reads it from a response.
     *
     * @param bool   $ok     whether the probe passed: a 2xx, not a 503
     * @param string $status the "status" word the server answered with, as sent
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $status,
    ) {}

    /**
     * Hydrate the answer from a probe's body. A probe calls no api_version, so
     * none is passed; $ok is the HTTP status's verdict, which the body does
     * not carry.
     *
     * @param array<string, mixed> $data the body's members as HealthTransport passes them
     *
     * @throws MalformedResponseException when "status" is absent or not a string
     */
    public static function fromArray(array $data, bool $ok): self
    {
        return new self(
            ok: $ok,
            status: Json::string($data, 'status'),
        );
    }
}
