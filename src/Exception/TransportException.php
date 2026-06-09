<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

/**
 * TransportException wraps a network-level failure (connection error, timeout,
 * or an undecodable response body) — i.e. the SDK never received a usable HTTP
 * response from Octonomy. Inspect getPrevious() for the underlying cause.
 */
final class TransportException extends OctonomyException {}
