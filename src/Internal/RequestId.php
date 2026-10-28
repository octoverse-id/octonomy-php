<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Internal;

/**
 * RequestId checks the id a caller supplies for the X-Request-ID header: when a
 * RequestOptions is built, and again in Transport::send(), since the PHP 7.4
 * line has no readonly and a checked value can be changed afterwards.
 *
 * Not a server rule. The server takes any value and records it as it arrives.
 * These are what the header can carry, plus the one property a correlation id
 * has to keep: the server must record the very string the caller logged,
 * because the audit row, the event and the server's log line are found by
 * string equality. Probed against server 3.2.1:
 *
 * - a control character other than a tab is refused by Guzzle, with an error
 *   that quotes the whole header from frames that hold the Authorization header
 *   and the body (Redaction);
 * - a byte above 0x7E is sent as is and read by the server as Latin-1 (WSGI,
 *   PEP 3333), so "req-café" is recorded as "req-cafÃ©": correlation is lost
 *   with a 2xx and no error anywhere;
 * - a leading or trailing space is trimmed on the way, by Guzzle's PSR-7
 *   messages among others, so the server records an id other than the one the
 *   caller logged;
 * - a blank value is sent as an empty header, which the server reads as absent
 *   and mints its own over.
 *
 * So an id must be non-blank printable ASCII (0x20 to 0x7E) with no space at
 * either end; a tab is refused with the other control characters, as the Go
 * SDK refuses it.
 *
 * The server stores the id in a 100-character column (audit and events), and a
 * longer one fails the row insert: a mutation answers 500 with an HTML page and
 * no error envelope, and is rolled back (probed against 3.2.1). That width is a
 * server rule, which a later release may widen, so it is documented and not
 * checked here (AGENTS.md).
 *
 * @internal
 */
final class RequestId
{
    private function __construct() {}

    /**
     * $id, when the server would record it as given.
     *
     * The message names the problem by its byte and offset rather than quoting
     * the id, which can hold a byte a log cannot print.
     *
     * @throws \InvalidArgumentException when $id is blank, holds a byte outside printable ASCII, or begins
     *                                   or ends with a space
     */
    public static function check(string $id): string
    {
        // Scrubbed like an OctonomyException: in Transport::send() the frames
        // below this one hold the request body.
        if (trim($id) === '') {
            throw Redaction::scrub(new \InvalidArgumentException(
                'octonomy: requestId is blank, and the server reads an empty X-Request-ID as absent. Fix: pass'
                . ' the id you log for this call, such as a UUID or your trace id, or leave requestId null to have'
                . ' the server mint one.',
            ));
        }
        if (preg_match('/[^\x20-\x7E]/', $id, $match, PREG_OFFSET_CAPTURE) === 1) {
            throw Redaction::scrub(new \InvalidArgumentException(sprintf(
                'octonomy: requestId holds byte 0x%02X at offset %d, outside printable ASCII (0x20 to 0x7E), which'
                . ' is all a request id may hold. A header cannot carry a control character other than a tab, and'
                . ' the server reads a byte above 0x7E as Latin-1, so it would record an id other than the one you'
                . ' logged. Fix: pass a printable ASCII id, such as a UUID, and remove any trailing newline left by'
                . ' reading it from a file.',
                ord($match[0][0]),
                $match[0][1],
            )));
        }
        if (trim($id, ' ') !== $id) {
            throw Redaction::scrub(new \InvalidArgumentException(sprintf(
                'octonomy: requestId "%s" begins or ends with a space, which is trimmed from a header value on the'
                . ' way, so the server would record "%s" and not the id you logged. Fix: pass the trimmed id.',
                $id,
                trim($id, ' '),
            )));
        }

        return $id;
    }
}
