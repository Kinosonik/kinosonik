<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

header('Content-Type: text/plain; charset=UTF-8');

$req = ['BASE_PATH','R2_ACCOUNT_ID','R2_ENDPOINT','R2_ACCESS_KEY','R2_SECRET_KEY','R2_BUCKET'];
$miss = [];
foreach ($req as $k) {
  $v = getenv($k) ?: ($_ENV[$k] ?? '');
  if ($v === '') $miss[] = $k;
}

echo "CONFIG SANITY\n=============\n";
if ($miss) {
  echo "FALTEN claus: " . implode(', ', $miss) . "\n";
  http_response_code(500);
} else {
  echo "OK: totes les claus requerides presents.\n";
}