<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

use Octoverse\Octonomy\Internal\ErrorMessage;

/**
 * WalkInterruptedException means a walk stopped part of the way through its
 * list: the request for one of its pages failed. It is how the walk hands back
 * how far it got, since a \Generator that throws can return nothing.
 *
 * $reachedOffset is the offset the failed page was asked for. The walk yielded
 * every item from where it started up to that offset, which is none when its
 * first page failed, and nothing from there on. getPrevious() is the failure
 * itself, always an
 * OctonomyException (an ApiException, a TransportException or a
 * MalformedResponseException) whose RequestContext is the failed page's
 * request.
 *
 * ```php
 * try {
 *     foreach ($client->tags()->walk($params) as $tag) { ... }
 * } catch (WalkInterruptedException $e) {
 *     $resume = $e->reachedOffset;   // 1000
 *     $e->getPrevious();             // the underlying 503
 * }
 * ```
 *
 * Resume by walking again with the same params and offset $reachedOffset. The
 * walk resumes where this one stopped only while the list holds still: an
 * offset is a position, not an identity, so a row created or removed before
 * that position since then moves the rest, and the resumed walk yields one
 * again or never yields one (see the walk methods' doc comments). A mistake in
 * the call itself, such as a v2-only feature on a v1 client, is thrown as it
 * is, not as this: there is nothing to resume.
 */
final class WalkInterruptedException extends OctonomyException
{
    /**
     * @param int               $reachedOffset the offset the failed page was asked for, where the walk's
     *                                         yielded items end
     * @param OctonomyException $previous      why the request for that page failed
     */
    public function __construct(
        public readonly int $reachedOffset,
        OctonomyException $previous,
    ) {
        parent::__construct(ErrorMessage::walkInterrupted($reachedOffset, $previous->getMessage()), 0, $previous);
    }
}
