<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

/**
 * ConfigurationException is thrown when the SDK is constructed with invalid
 * configuration (e.g. a missing base URL, token, or tenant ID).
 */
final class ConfigurationException extends OctonomyException {}
