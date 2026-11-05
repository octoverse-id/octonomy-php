<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Http;

use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Exception\OctonomyException;
use Octoverse\Octonomy\Exception\WalkInterruptedException;
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
     * walk is iterated.
     *
     * @template T
     *
     * @param \Closure(array<string, mixed>, string): T $hydrate builds one item, as for Transport::many()
     * @param array<string, string>                      $query   the params' toQuery(); its limit, when set,
     *                                                            is the caller's and wins
     * @param int|null                                   $offset  where the walk starts, sent as it is; null for
     *                                                            the beginning
     *
     * @return \Generator<int, T, mixed, void>
     */
    public static function walk(
        Transport $transport,
        string $path,
        \Closure $hydrate,
        array $query,
        ?int $offset,
        ?RequestOptions $options,
    ): \Generator {
        $query['limit'] ??= (string) self::DEFAULT_LIMIT;
        $offset ??= 0;
        $first = true;

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

            // The server echoes the offset it served. The first page starts
            // wherever the server says it does, since an offset it cannot
            // serve is its to read (it serves a negative one from 0), and the
            // walk counts its keys from there. Every later page must start
            // where the walk asked: a page from anywhere else means something
            // between the SDK and the server dropped the offset, and the walk
            // would ask for that same page again for ever.
            $served = $page->pagination->offset;
            if ($first) {
                $offset = $served;
                $first = false;
            } elseif ($served !== $offset) {
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
