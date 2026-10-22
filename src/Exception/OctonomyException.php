<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

/**
 * OctonomyException is the base type for every failure the SDK reports. Catch
 * it to handle any SDK failure uniformly.
 *
 * A mistake in the calling code that the SDK detects before sending anything is
 * not such a failure, and is thrown as PHP's own \LogicException family
 * instead: a NamespaceScope with a blank type or id (\InvalidArgumentException),
 * or a v2-only feature on a v1 client (\LogicException). Fix the call; there is
 * nothing to retry.
 */
class OctonomyException extends \RuntimeException {}
