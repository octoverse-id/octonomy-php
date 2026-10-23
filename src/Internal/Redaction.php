<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Internal;

/**
 * Redaction is the SDK's list of what no exception it throws may carry, and the
 * one place that list is enforced on stack frames.
 *
 * Never in a message, a RequestContext, a stack frame, or an exception chained
 * as getPrevious():
 *
 * - the token (Config::$token);
 * - the Authorization header, which carries the token;
 * - the request body;
 * - X-Actor-ID (Config::$actorId, RequestOptions::$actorId).
 *
 * Messages and RequestContext keep them out by construction: no message is
 * built from any of them, and RequestContext has no field that could hold one.
 * A token or actor id that an HTTP header cannot carry is refused before Guzzle
 * sees it, because Guzzle's own error quotes the whole header value.
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

    /** The namespace a frame's class is in when the frame is the SDK's own. */
    private const SDK_NAMESPACE = 'Octoverse\\Octonomy\\';

    private function __construct() {}

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
     * quotes it in full, so a credential must be checked before it gets there.
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
