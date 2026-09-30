<?php

declare(strict_types=1);

return [
    // Letters, digits and underscores only (validated at start); empty / unset → loongs.
    'name' => env('APP_NAME', 'loongs'),
    'env' => env('APP_ENV', 'local'),
    'debug' => filter_var(env('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOLEAN),
    'timezone' => 'Asia/Shanghai',
];
