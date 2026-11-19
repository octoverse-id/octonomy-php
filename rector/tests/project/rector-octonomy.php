<?php

declare(strict_types=1);

// The configuration docs/upgrading-1-to-2.md tells a project to save as rector-octonomy.php, with
// only this comment and the strict_types line added.

use Octoverse\Octonomy\Rector\FlagModelPropertyWriteRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withRules([FlagModelPropertyWriteRector::class]);
