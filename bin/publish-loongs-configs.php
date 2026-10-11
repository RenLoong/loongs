#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Run every installed loongs package publish-config.php (missing config stubs only).
 * Wired from this project's Composer post-autoload-dump.
 */
$vendorLoongs = getcwd() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'loongs';
if (!is_dir($vendorLoongs)) {
    exit(0);
}

$pattern = $vendorLoongs . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'publish-config.php';
$scripts = glob($pattern) ?: [];
foreach ($scripts as $script) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    passthru($cmd, $code);
    if ($code !== 0) {
        exit($code);
    }
}

exit(0);
