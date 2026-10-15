<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

/**
 * MalformedResponseException means Octonomy answered with a 2xx whose JSON body
 * does not have the envelope the endpoint guarantees — for example a
 * single-resource response with no {"data": {...}} object, or a list response
 * without its {"data": [...], "pagination": {...}} pair. The SDK raises it
 * instead of hydrating an empty model, so contract drift fails loudly at the
 * call site. The message names the endpoint and what was expected.
 */
final class MalformedResponseException extends OctonomyException {}
