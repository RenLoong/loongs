<?php

declare(strict_types=1);

/**
 * Extra `./loongs` console commands (symfony/console Command classes with #[AsCommand]).
 * Per-app commands: apps/<App>/config/console.php with the same shape.
 * Extend Loongs\Console\Command to get basePath() / io() / processManager() / rpcServices().
 */
return [
    'commands' => [
        // App\Website\Console\CacheWarmCommand::class,
        \Loongs\OAuth\Console\InstallCommand::class,   // ./loongs oauth:install [connection] (loongs/oauth tables)
    ],
];
