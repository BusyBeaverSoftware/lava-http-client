<?php

declare(strict_types=1);

/*
 * Test bootstrap that works in both layouts:
 *  - standalone package install: packages/<pkg>/vendor/autoload.php
 *  - monorepo root install:       vendor/autoload.php (two levels up)
 */
$packageRoot = dirname(__DIR__);
$candidates = [
    $packageRoot . '/vendor/autoload.php',
    dirname($packageRoot, 2) . '/vendor/autoload.php',
];
foreach ($candidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        return;
    }
}
fwrite(STDERR, "No vendor/autoload.php found in {$packageRoot} or the monorepo root. Run: composer install\n");
exit(1);