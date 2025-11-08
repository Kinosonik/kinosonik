<?php
require __DIR__ . '/../../vendor/autoload.php';
echo "autoload OK\n";
new setasign\Fpdi\Fpdi();
echo "FPDI OK\n";
$qr = new Endroid\QrCode\QrCode('hello');
echo "QR OK\n";
?>