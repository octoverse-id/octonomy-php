<?php

declare(strict_types=1);

use Octoverse\Octonomy\Tools\Rector\DataProviderAttributeToAnnotationRector;
use Rector\Config\RectorConfig;

// The downgrade of src/, tests/ and examples/ to PHP 7.4, run by scripts/php74-downgrade.sh. Paths
// come from that script, never from here, so this config cannot rewrite the 8.2 tree in place.
//
// withDowngradeSets(php74: true) is the level set down-to-php74, which composes every downgrade
// from PHP 8.5 to 7.4. The standalone set file downgrade-php74.php is not it: that one targets 7.3.
return RectorConfig::configure()
    ->withDowngradeSets(php74: true)
    ->withRules([DataProviderAttributeToAnnotationRector::class])
    // A dedicated cache, cleared on every run (--clear-cache), so a stale entry can never make Rector
    // skip a file and leave it in 8.2 syntax.
    ->withCache(cacheDirectory: sys_get_temp_dir() . '/octonomy-php-rector');
