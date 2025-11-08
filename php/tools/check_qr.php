<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;

$out = __DIR__ . '/qr_test.png';
$res = Builder::create()->data('https://example.com')->build();
$res->saveToFile($out);
echo "QR OK -> $out\n";
?>