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

    /**
     * @param list<string> $values the values to replace, longest first
     */
    private function __construct(private readonly array $values) {}

    /**
     * The values on the list that one request carries: its token, the actor id
     * it sends, the body whole as it is sent, and every scalar in the body at
     * any depth (true, false and null included), and every key below the top
     * level. A metadata key is the caller's content; a top-level key is a field
     * name of the API, which the SDK's own words also use, so it stays. Each is
     * also taken as JSON escapes it, since an echo of the body is JSON.
     *
     * The body is read as it goes on the wire: the JSON Guzzle sends (with
     * json_encode()'s default flags, as Guzzle uses), decoded, so an object
     * inside it is walked as the object it encodes to.
     *
     * @param array<array-key, mixed>|null $body
     */
    public static function of(string $token, ?string $actorId, ?array $body): self
    {
        $values = [$token];
        if ($actorId !== null) {
            $values[] = $actorId;
        }
        if ($body !== null) {
            // A body that does not encode is never sent (Guzzle refuses it), so
            // walking it as given is enough.
            $encoded = json_encode($body);
            if ($encoded !== false) {
                $values[] = $encoded;
            }
            $sent = $encoded === false ? $body : json_decode($encoded, true);
            if (is_array($sent)) {
                self::collect($sent, true, $values);
            }
        }
        $values = array_values(array_unique(array_filter($values, static fn(string $value): bool => $value !== '')));
        usort($values, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return new self($values);
    }

    /**
     * A redaction with nothing to replace, for text no request's values could
     * have reached.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * $text with every value this redaction holds replaced by MARK.
     */
    public function text(string $text): string
    {
        foreach ($this->values as $value) {
            $text = strlen($value) >= self::DISTINCT
                ? str_replace($value, self::MARK, $text)
                : (string) preg_replace('/(?<![A-Za-z0-9])' . preg_quote($value, '/') . '(?![A-Za-z0-9])/', self::MARK, $text);
        }

        return $text;
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
     * Add every scalar in $node, as JSON writes it, and its string keys unless
     * $top, to $values, as given and as JSON escapes it.
     *
     * @param array<array-key, mixed> $node
     * @param list<string>            $values
     */
    private static function collect(array $node, bool $top, array &$values): void
    {
        foreach ($node as $key => $value) {
            $found = [];
            if (!$top && is_string($key)) {
                $found[] = $key;
            }
            if (is_array($value)) {
                self::collect($value, false, $values);
            } elseif (is_string($value) || is_int($value) || is_float($value)) {
                $found[] = (string) $value;
            } elseif (is_bool($value) || $value === null) {
                $found[] = (string) json_encode($value);
            }
            foreach ($found as $text) {
                $values[] = $text;
                $values[] = substr((string) json_encode($text), 1, -1);
            }
        }
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
