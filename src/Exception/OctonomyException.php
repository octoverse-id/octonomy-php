<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

/**
 * OctonomyException is the base type for every exception thrown by the SDK.
 * Catch it to handle any SDK failure uniformly.
 */
class OctonomyException extends \RuntimeException {}
