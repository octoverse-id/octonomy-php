<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

use Octoverse\Octonomy\Internal\ErrorMessage;
use Octoverse\Octonomy\Internal\Redaction;

/**
 * Verifies the signature on a webhook delivery from Octonomy.
 *
 * The server signs the exact bytes of each delivery's body with HMAC-SHA256
 * under the shared signing secret (its OCTONOMY_WEBHOOK_SIGNING_SECRET) and
 * sends the lowercase hex digest in the X-Octonomy-Signature header as
 * `sha256=<digest>` (octonomy/events/dispatch.py::_webhook_signature).
 *
 * **Reject a delivery that does not verify, with any status but a 2xx.** The
 * server marks a delivery answered with a 2xx as delivered and never sends it
 * again; a handler that answers 200 after a failed check is wrong for good, and
 * nothing tells it so. A non-2xx leaves the event failed, for the server's
 * dispatcher to retry or dead-letter. Set the failure status before calling
 * verify(): plain PHP answers an uncaught exception, such as the one verify()
 * throws for a missing secret, with 200 while display_errors is on.
 *
 * Only the body is signed. The other X-Octonomy-* headers are not, so a captured
 * genuine delivery still verifies with its X-Octonomy-Tenant-ID rewritten: read
 * the tenant, the event type and the event id from the verified body, never from
 * a header. Nothing is signed about when a delivery was sent, so a verified one
 * can be a replay, and the server delivers at least once anyway: deduplicate on
 * the body's `id`.
 */
final class Webhook
{
    /** The header the signature arrives in. */
    public const SIGNATURE_HEADER = 'X-Octonomy-Signature';

    private function __construct() {}

    /**
     * Whether $rawBody carries a valid Octonomy signature under $secret.
     *
     * It answers false for anything the sender controls: no signature (null or
     * ''), a value that is not `sha256=` followed by 64 hex digits, and a digest
     * that is not the body's. Every delivery failing usually means something
     * read the body before this call did, leaving it partly or wholly unread:
     * pass the bytes exactly as received.
     *
     * It throws InvalidArgumentException only for the caller's own mistakes,
     * which no sender can cause: a secret that is not a string, or is empty,
     * since under an empty key anyone can sign a delivery that verifies; a
     * header passed as anything but a string or null (such as PSR-7's
     * getHeader() array); and a body that is not a string (such as a failed
     * read's false, or a parsed body, whose bytes are gone). Refusing the empty
     * secret is the one rule here beyond the HMAC itself, and it is the
     * verifier's own: no server rule is involved.
     *
     * The parameters are declared mixed, and the types above are checked here,
     * so that a wrong one never stops the call at its boundary: that TypeError
     * would hold the secret in this frame and in every caller's frame that
     * passed it down, before any code here could remove it. Every exception
     * thrown here goes through Redaction::scrubAll(), which removes the
     * arguments of every frame in its trace, on either PHP line.
     * #[\SensitiveParameter] marks the secret as well on PHP 8.2+.
     *
     * The digests are compared with hash_equals(), never with == or ===, which
     * would reveal through timing how much of a forged digest is right. The hex
     * is decoded first, so its case does not matter; the `sha256=` prefix must
     * be exact, since it names the algorithm.
     *
     * @param string      $secret          the secret the Octonomy server signs with
     * @param string|null $signatureHeader the X-Octonomy-Signature header's value as received, or null
     *                                     when the request has none
     * @param string      $rawBody         the request body, as the exact bytes received, such as
     *                                     file_get_contents('php://input'); never a parsed or re-encoded
     *                                     one
     *
     * @throws \InvalidArgumentException when an argument is the caller's mistake, as above
     */
    public static function verify(
        #[\SensitiveParameter]
        mixed $secret,
        mixed $signatureHeader,
        mixed $rawBody,
    ): bool {
        // The caller's mistakes first: they are true whatever the sender did.
        if (!is_string($secret)) {
            throw Redaction::scrubAll(new \InvalidArgumentException(ErrorMessage::webhookArgument(
                'the signing secret',
                $secret,
                'pass the secret the Octonomy server signs with, its OCTONOMY_WEBHOOK_SIGNING_SECRET, as a string;'
                . ' getenv() returns false for a variable that is not set.',
            )));
        }
        if ($secret === '') {
            throw Redaction::scrubAll(new \InvalidArgumentException(ErrorMessage::webhookEmptySecret()));
        }
        if ($signatureHeader !== null && !is_string($signatureHeader)) {
            throw Redaction::scrubAll(new \InvalidArgumentException(ErrorMessage::webhookArgument(
                'the signature header',
                $signatureHeader,
                'pass the X-Octonomy-Signature header\'s value as a string, such as PSR-7\'s'
                . ' getHeaderLine() rather than getHeader(), or null when the request has none.',
            )));
        }
        if (!is_string($rawBody)) {
            throw Redaction::scrubAll(new \InvalidArgumentException(ErrorMessage::webhookArgument(
                'the body',
                $rawBody,
                'pass the request body as the exact bytes received, such as file_get_contents(\'php://input\'),'
                . ' after checking that the read did not return false. The signature covers those bytes, so a'
                . ' parsed body cannot be verified.',
            )));
        }

        // Then what the sender controls, each a plain no.
        if ($signatureHeader === null) {
            return false;
        }
        // \z, not $: $ would also match before a trailing newline.
        if (preg_match('/\Asha256=([0-9a-fA-F]{64})\z/', $signatureHeader, $match) !== 1) {
            return false;
        }
        $received = hex2bin($match[1]);
        if ($received === false) {
            return false;
        }

        // The digest of the body is computed in this one place, directly inside
        // the comparison; WebhookTest holds it there.
        return hash_equals(hash_hmac('sha256', $rawBody, $secret, true), $received);
    }
}
