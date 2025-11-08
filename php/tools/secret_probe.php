<?php
declare(strict_types=1);
$cfg = require dirname(__DIR__) . '/secret.php';
header('Content-Type: text/plain; charset=UTF-8');
echo "OK secrets\n";
echo "BASE_PATH=" . ($cfg['BASE_PATH'] ?? '(no)') . "\n";
echo "APP_ENV=" . ($cfg['APP_ENV'] ?? '(no)') . "\n";