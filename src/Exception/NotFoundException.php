<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

/**
 * NotFoundException is thrown for a 404 / not_found response — the requested
 * resource does not exist within the tenant.
 */
final class NotFoundException extends ApiException {}
