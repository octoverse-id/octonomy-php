<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Internal;

/**
 * Redaction is the SDK's list of what no exception it throws may carry, and the
 * one place that list is enforced: on the text a message quotes, and on stack
 * frames.
 *
 * Never in a message, a RequestContext, a stack frame, or an exception chained
 * as getPrevious():
 *
 * - the token (Config::$token);
 * - the Authorization header, which carries the token;
 * - the request body;
 * - X-Actor-ID (Config::$actorId, RequestOptions::$actorId).
 *
 * RequestContext has no field that could hold one, and the SDK's own words in
 * a message never interpolate one. A message also quotes text the SDK did not
 * write: the HTTP client's error, the server's message, detail, details'
 * names, request id and Content-Type, and the value of a field a model
 * rejects (MalformedResponseException::locate()). An injected client or
 * middleware, or a server that echoes its input, can put any of the four in
 * that text, so it passes through text() first, which replaces the values the
 * request carried (of()). The server's own data stays as sent in
 * ApiException::$serverMessage and $details, which are not messages.
 *
 * Every header value the SDK sends is checked before Guzzle sees it
 * (isHeaderSafe()), when it is built and again in Transport::send(), since the
 * PHP 7.4 line has no readonly. Guzzle refuses a bad one with an error that
 * quotes it, thrown from frames whose arguments hold every header, the
 * Authorization one included, and the body.
 *
 * Stack frames are where they leak by default. PHP records every argument of
 * every frame in an exception's trace, so the frame of TagService::create()
 * holds the body and the RequestOptions, and the frame of Config::__construct()
 * holds the token. Error trackers capture those arguments unless
 * zend.exception_ignore_args is on. scrub() removes the arguments of the SDK's
 * own frames. OctonomyException runs it on every exception the SDK defines,
 * and Transport runs it on the \LogicException it throws.
 *
 * This is done here rather than with #[\SensitiveParameter] because the PHP 7.4
 * line has no attributes, and the redaction must hold on both lines.
 *
 * The HTTP client's exception is never chained, because it holds the request
 * it failed to send, Authorization header and body included: Guzzle's
 * RequestException and ConnectException expose it through getRequest(), as
 * PSR-18 requires of every client exception.
 *
 * @internal
 */
final class Redaction
{
    public const TOKEN = 'token';
    public const AUTHORIZATION = 'Authorization header';
    public const BODY = 'request body';
    public const ACTOR = 'X-Actor-ID';

    /**
     * Every item above, so a test can plant each one and fail when one is added
     * without being planted.
     */
    public const ALL = [self::TOKEN, self::AUTHORIZATION, self::BODY, self::ACTOR];

    /** What text() puts in place of a value. */
    public const MARK = '[redacted]';

    /** The namespace a frame's class is in when the frame is the SDK's own. */
    private const SDK_NAMESPACE = 'Octoverse\\Octonomy\\';

    /**
     * The length from which text() replaces a value wherever it appears. A
     * shorter one is replaced only where it stands alone, so an actor id of
     * "1" does not eat the digits of a port number in a client's error.
     */
    private const DISTINCT = 8;

    /** @var list<string>|null the values to replace, longest first, read from the snapshot on first use */
    private ?array $values = null;

    /**
     * @param string|null  $body the request body as it was sent, JSON-encoded, or null for none
     * @param list<string> $also other values on the list the request carries
     */
    private function __construct(
        private readonly ?string $token,
        private readonly ?string $actorId,
        private readonly ?string $body,
        private readonly array $also = [],
    ) {}

    /**
     * A snapshot of the values on the list that one request carries: its
     * token, the actor id it sends, and its body as the JSON it is sent as.
     * Take it from exactly what is handed to the HTTP client, before the
     * client runs: every value is a string from then on, so nothing the
     * client's middleware changes afterwards changes what is redacted.
     *
     * text() replaces the token, the actor id, the body whole, and every
     * scalar and every object key in the body at any depth, except the keys of
     * the body itself: those are the API's field names, which the SDK's own
     * words also use. A string is taken as given and as JSON escapes it; a
     * number as it was sent, as JSON writes it and as PHP prints it; true,
     * false and null as JSON writes them.
     *
     * @param string|null  $body the request body as sent, JSON-encoded, or null for none
     * @param list<string> $also other values on the list the request carries, such as an X-Actor-ID
     *                           the HTTP client adds on its own
     */
    public static function of(string $token, ?string $actorId, ?string $body, array $also = []): self
    {
        return new self($token, $actorId, $body, $also);
    }

    /**
     * A redaction with nothing to replace, for text no request's values could
     * have reached.
     */
    public static function none(): self
    {
        return new self(null, null, null);
    }

    /**
     * $text with every value this redaction holds replaced by MARK.
     */
    public function text(string $text): string
    {
        foreach ($this->values() as $value) {
            $text = strlen($value) >= self::DISTINCT
                ? str_replace($value, self::MARK, $text)
                : (string) preg_replace('/(?<![A-Za-z0-9])' . preg_quote($value, '/') . '(?![A-Za-z0-9])/', self::MARK, $text);
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function values(): array
    {
        if ($this->values === null) {
            $values = [(string) $this->token, (string) $this->actorId, ...$this->also];
            if ($this->body !== null) {
                $values[] = $this->body;
                // Objects kept as objects: an associative array would turn a
                // key such as "123" into an index, and a list's indexes are
                // not in the JSON at all.
                self::collect(json_decode($this->body), true, $values);
                // Each number also as it was sent, which decoding cannot give
                // back: -0.0 goes out as "-0" and comes back as 0. Strings are
                // matched only to be skipped.
                preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|-?\\d+(?:\\.\\d+)?(?:[eE][+-]?\\d+)?/', $this->body, $tokens);
                foreach ($tokens[0] as $token) {
                    if ($token[0] !== '"') {
                        $values[] = $token;
                    }
                }
            }
            $values = array_values(array_unique(array_filter($values, static fn(string $value): bool => $value !== '')));
            usort($values, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
            $this->values = $values;
        }

        return $this->values;
    }

    /**
     * Add every scalar in $node, a value json_decode() returned, and every key
     * of the objects in it except $node's own when it is the body itself
     * ($top), to $values.
     *
     * @param list<string> $values
     */
    private static function collect(mixed $node, bool $top, array &$values): void
    {
        if ($node instanceof \stdClass) {
            foreach (get_object_vars($node) as $key => $value) {
                if (!$top) {
                    self::add((string) $key, $values);
                }
                self::collect($value, false, $values);
            }
        } elseif (is_array($node)) {
            foreach ($node as $value) {
                self::collect($value, false, $values);
            }
        } elseif (is_string($node)) {
            self::add($node, $values);
        } else {
            $values[] = (string) json_encode($node);
            if (is_int($node) || is_float($node)) {
                $values[] = (string) $node;
            }
        }
    }

    /**
     * Add $text to $values as given and as JSON escapes it inside a string.
     *
     * @param list<string> $values
     */
    private static function add(string $text, array &$values): void
    {
        $values[] = $text;
        $values[] = substr((string) json_encode($text), 1, -1);
    }

    /**
     * Remove the arguments of every SDK frame from $e's trace, and from the
     * trace of each exception it chains. A frame outside the SDK keeps its
     * arguments: it is the caller's code or PHP's, and what it holds is the
     * caller's to redact.
     *
     * @template T of \Throwable
     *
     * @param T $e
     *
     * @return T
     */
    public static function scrub(\Throwable $e): \Throwable
    {
        // An \Error keeps its trace on \Error, not \Exception. The SDK chains
        // none, and throws none of its own.
        $trace = new \ReflectionProperty(\Exception::class, 'trace');
        for ($link = $e; $link !== null; $link = $link->getPrevious()) {
            if ($link instanceof \Exception) {
                $trace->setValue($link, self::withoutSdkArguments($link->getTrace()));
            }
        }

        return $e;
    }

    /**
     * Whether $value can be sent as an HTTP header value: no control character
     * other than a tab. Guzzle refuses any other value with an error that
     * quotes it in full, thrown from frames that hold the Authorization header
     * and the body, so every header value is checked before it gets there.
     */
    public static function isHeaderSafe(string $value): bool
    {
        return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 0;
    }

    /**
     * @param list<array<string, mixed>> $frames
     *
     * @return list<array<string, mixed>>
     */
    private static function withoutSdkArguments(array $frames): array
    {
        // A new array, not edited in place by reference: a frame that is still
        // a reference when the trace is set makes getTraceAsString() warn.
        $out = [];
        foreach ($frames as $frame) {
            $class = $frame['class'] ?? null;
            if (is_string($class) && str_starts_with($class, self::SDK_NAMESPACE)) {
                unset($frame['args']);
            }
            $out[] = $frame;
        }

        return $out;
    }
}
