<?php
// php/r2_smoke.php — Smoke test del client R2 (només ADMIN)
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { @session_start(); }

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/r2.php';
require_once __DIR__ . '/audit.php'; // per registrar l’ús, si cal

// No mostris errors a pantalla (log a FPM/fitxer)
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

header('Content-Type: text/plain; charset=UTF-8');

// ── Protecció: només ADMIN
if (strcasecmp((string)($_SESSION['tipus_usuari'] ?? ''), 'admin') !== 0) {
  http_response_code(403);
  echo "403 — Forbidden\n";
  exit;
}

// Helper petit per llegir variables d'entorn/secret
if (!function_exists('env_first')) {
  function env_first(array $keys, string $default = ''): string {
    foreach ($keys as $k) {
      $v = getenv($k);
      if ($v !== false && $v !== '') return (string)$v;
      if (!empty($_ENV[$k]))    return (string)$_ENV[$k];
      if (!empty($_SERVER[$k])) return (string)$_SERVER[$k];
    }
    return $default;
  }
}

// (Opcional) Auditoria d’accés
try {
  if (function_exists('db')) {
    audit_admin(
      db(),
      (int)($_SESSION['user_id'] ?? 0),
      true,
      'r2_smoke',
      null,
      null,
      'admin',
      null,
      'success',
      null
    );
  }
} catch (Throwable $e) {
  error_log('audit r2_smoke failed: ' . $e->getMessage());
}

echo "R2 SMOKE TEST\n";

$autoload = dirname(__DIR__) . "/vendor/autoload.php";
echo "autoload path : {$autoload}\n";
echo "autoload exists? " . (is_file($autoload) ? "YES\n" : "NO\n");

// Variables d'entorn (carregades via secret.local.php/config.php)
$acc = env_first(['R2_ACCOUNT_ID']);
$end = env_first(['R2_ENDPOINT']);
$key = env_first(['R2_ACCESS_KEY','R2_ACCESS_KEY_ID']);
$sec = env_first(['R2_SECRET_KEY','R2_SECRET_ACCESS_KEY']);

echo "ENV R2_ACCOUNT_ID : " . ($acc !== '' ? $acc : '(buit)') . "\n";
echo "ENV R2_ENDPOINT   : " . ($end !== '' ? $end : '(buit)') . "\n";
echo "ENV R2_ACCESS_KEY*: " . ($key !== '' ? '(present)' : '(buit)') . "\n";
echo "ENV R2_SECRET_KEY*: " . ($sec !== '' ? '(present)' : '(buit)') . "\n";

try {
  $client = r2_client();
  echo "OK: client creat\n";
} catch (Throwable $e) {
  echo "ERROR creant client: " . $e->getMessage() . "\n";
  exit(1);
}

echo "FI\n";