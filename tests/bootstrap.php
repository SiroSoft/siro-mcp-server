<?php

declare(strict_types=1);

// Autoload from composer
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "ERROR: vendor/autoload.php not found. Run 'composer install' first.\n");
    exit(1);
}

require $autoload;
