<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Http;

use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Exception\OctonomyException;
use Octoverse\Octonomy\Exception\WalkInterruptedException;
use Octoverse\Octonomy\Internal\ErrorMessage;
use Octoverse\Octonomy\Internal\Redaction;
use Octoverse\Octonomy\RequestOptions;

/**
 * Walker is the loop behind every service's walk methods: it reads a list
 * endpoint page by page through Transport::many(), one request per page, and
 * yields each item keyed by its offset in the list.
 *
 * Each page is asked for at the offset the one before it ended on, which is
 * the start offset plus the items yielded so far, never plus the limit asked
 * for: the server clamps a limit above its maximum of 200, and serves its
 * default of 50 for one below 1, without saying so. The walk ends on the
 * server's own signal, a page with no next link, or on an empty page, which
 * guarantees it ends even if that signal never comes.
 *
 * @internal
 */
final class Walker
{
    /**
     * The limit a walk asks for when the caller sets none: the server's maximum
     * (octonomy/core/pagination.py max_limit), not its default of 50, which
     * would take four times the requests. The server clamps a larger limit
     * rather than refusing it, so this stays safe if the maximum drops.
     */
    public const DEFAULT_LIMIT = 200;

    private function __construct() {}

    /**
     * Walk GET $path from $offset, sending $query, the list's own params, on
     * every page with the page's limit and offset. Nothing is sent until the
     * walk is iterated; a negative $offset is refused here, at the call.
     *
     * @template T
     *
     * @param \Closure(array<string, mixed>, string): T $hydrate builds one item, as for Transport::many()
     * @param array<string, string>                      $query   the params' toQuery(); its limit, when set,
     *                                                            is the caller's and wins
     * @param int|null                                   $offset  where the walk starts; null for the beginning
     *
     * @return \Generator<int, T, mixed, void>
     *
     * @throws \InvalidArgumentException when $offset is negative (nothing is sent)
     */
    public static function walk(
        Transport $transport,
        string $path,
        \Closure $hydrate,
        array $query,
        ?int $offset,
        ?RequestOptions $options,
    ): \Generator {
        // Refused rather than sent: the server reads a negative offset as 0
        // without saying so, and every offset the walk then reported, its
        // keys and a WalkInterruptedException's reachedOffset, would be
        // wrong by the difference.
        if ($offset !== null && $offset < 0) {
            throw Redaction::scrub(new \InvalidArgumentException(ErrorMessage::negativeWalkOffset($offset)));
        }
        $query['limit'] ??= (string) self::DEFAULT_LIMIT;

        return self::pages($transport, $path, $hydrate, $query, $offset ?? 0, $options);
    }

    /**
     * The generator walk() returns, kept apart so that walk() checks its
     * arguments when it is called: a generator function runs none of its body
     * until it is first iterated.
     *
     * @template T
     *
     * @param \Closure(array<string, mixed>, string): T $hydrate
     * @param array<string, string>                      $query
     *
     * @return \Generator<int, T, mixed, void>
     */
    private static function pages(
        Transport $transport,
        string $path,
        \Closure $hydrate,
        array $query,
        int $offset,
        ?RequestOptions $options,
    ): \Generator {
        while (true) {
            $query['offset'] = (string) $offset;
            try {
                $page = $transport->many('GET', $path, $hydrate, $query, null, $options);
            } catch (OctonomyException $e) {
                // Only a failure the server or the network caused. A mistake
                // in the call, a v2-only feature on a v1 client or a header
                // value that cannot be sent, is a \LogicException and passes
                // through as it is: no resume would get past it.
                throw new WalkInterruptedException($offset, $e);
            }

            // The server echoes the offset it served, and serves the one it
            // was asked for. A page from anywhere else means something between
            // the SDK and the server dropped the offset, and the walk would
            // ask for that same page again forever.
            $served = $page->pagination->offset;
            if ($served !== $offset) {
                throw new WalkInterruptedException($offset, new MalformedResponseException(
                    "is not {$offset}, the offset the walk asked for",
                    'pagination.offset',
                    $transport->context('GET', $path, $query, $options),
                    null,
                    null,
                    (string) $served,
                ));
            }

            foreach ($page->data as $item) {
                yield $offset => $item;
                ++$offset;
            }

            if ($page->data === [] || $page->pagination->next === null) {
                return;
            }
        }
    }
}
