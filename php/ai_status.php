<?php
// php/ai_status.php — retorna l'estat del job (amb auth bàsica i headers correctes)
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function jexit(array $payload, int $code = 200): never {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Accepta job_uid de 12–24 hex (coherent amb ai_start/ia_kick)
$job = isset($_GET['job']) ? (string)$_GET['job'] : '';
if (!preg_match('/^[a-f0-9]{12,24}$/i', $job)) {
  jexit(['error' => 'bad_job'], 400);
}

$stateFile = sys_get_temp_dir() . "/ai-$job.json";
if (!is_file($stateFile)) {
  header('Retry-After: 1');
  jexit(['pending' => true], 202);
}

// límit 64KB per evitar carregar fitxers massa grans
$raw = @file_get_contents($stateFile, false, null, 0, 64 * 1024);
if ($raw === false || $raw === '') {
  jexit(['error' => 'empty'], 500);
}

$state = json_decode($raw, true);
if (!is_array($state)) {
  jexit(['error' => 'bad_state'], 500);
}

// Auth bàsica: propietari o admin
$uidSession = (int)($_SESSION['user_id'] ?? 0);
$tipus      = (string)($_SESSION['tipus_usuari'] ?? '');
$isAdmin    = strcasecmp($tipus, 'admin') === 0;

$uidOwner = (int)($state['uid'] ?? 0);
if ($uidSession <= 0) {
  jexit(['error' => 'login_required'], 401);
}
if (!$isAdmin && $uidOwner !== $uidSession) {
  jexit(['error' => 'forbidden'], 403);
}

// TTL: marca "stale" si fa massa que no s'actualitza
$ts  = (int)($state['ts'] ?? 0);     // epoch segons worker/UI
$ttl = 30 * 60; // 30 minuts
if ($ts > 0 && (time() - $ts) > $ttl && empty($state['done'])) {
  $state['stale'] = true;
}

// Acota logs (últimes 200 línies)
if (isset($state['logs']) && is_array($state['logs'])) {
  $state['logs'] = array_slice($state['logs'], -200);
}

// Per conveniència client: afegeix hora servidor ISO (TZ Europe/Madrid si vols)
$state['server_time_iso'] = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('c');

jexit($state, 200);