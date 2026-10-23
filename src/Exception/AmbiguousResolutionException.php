<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

/**
 * AmbiguousResolutionException is thrown for a 400 ambiguous_resolution
 * response: more than one tag or alias matched a slug equally well, so the
 * server would not pick one. Narrow the request by application_id, type or
 * namespace and send it again; $details names the axis that disambiguates.
 *
 * Server 3.2.1 cannot reach it over HTTP (each resolution rung holds at most
 * one active row per type once an application is named, and a namespaced
 * request must name one), and reports a match on several types as a
 * validation_error instead. The server keeps the code as a guard, so the SDK
 * maps it for the release that reaches it.
 */
final class AmbiguousResolutionException extends ApiException {}
