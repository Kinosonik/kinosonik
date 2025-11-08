<?php
// php/admin/log_view.php — Mostra log d'un job d'IA (només ADMIN)
declare(strict_types=1);

require_once dirname(__DIR__) . '/preload.php';
require_once dirname(__DIR__) . '/config.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/audit.php';

header('X-Content-Type-Options: nosniff');

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Directori d’arrel de logs (ha d’existir com a constant o variable global del teu projecte)
$LOG_ROOT = (string)KS_SECURE_LOG_DIR;               // ve de php/config.php
$LOG_ROOT_REAL = rtrim($LOG_ROOT, DIRECTORY_SEPARATOR);

try {
  // 1) Auth/rol
  $uid = $_SESSION['user_id'] ?? null;
  if (!$uid) { throw new RuntimeException('login_required'); }
  $pdo = db();
  $st = $pdo->prepare('SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari=? LIMIT 1');
  $st->execute([$uid]);
  $role = (string)($st->fetchColumn() ?: '');
  if (strcasecmp($role, 'admin') !== 0) { throw new RuntimeException('forbidden'); }

  // 2) Paràmetres: job o rider (i modes)
  $job      = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['job'] ?? ''));
  $rider    = (int)($_GET['rider'] ?? 0);
  $mode     = (string)($_GET['mode'] ?? 'html');    // 'html' | 'raw'
  $download = ((string)($_GET['download'] ?? '0') === '1'); // només per mode=raw

  if ($job === '' && $rider <= 0) { throw new RuntimeException('bad_params'); }

  if ($job !== '') {
    $q = $pdo->prepare('SELECT rider_id, log_path, started_at FROM ia_runs WHERE job_uid=? LIMIT 1');
    $q->execute([$job]);
  } else {
    $q = $pdo->prepare('SELECT rider_id, log_path, started_at FROM ia_runs WHERE rider_id=? ORDER BY started_at DESC LIMIT 1');
    $q->execute([$rider]);
  }
  $row = $q->fetch(PDO::FETCH_ASSOC);
  if (!$row) { throw new RuntimeException('log_not_found'); }

  $riderId = (int)$row['rider_id'];
  $logPath = (string)$row['log_path'];
  if ($logPath === '' || !is_file($logPath)) { throw new RuntimeException('file_missing'); }
  if (!is_readable($logPath)) {
  throw new RuntimeException('file_not_readable');
  }
  if (is_link($logPath)) { throw new RuntimeException('symlink_blocked'); }

  // 2b) Seguretat de ruta: ha de caure dins LOG_ROOT
  $real = realpath($logPath);
$root = realpath($LOG_ROOT_REAL);
if ($root !== false) { $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR; }

if ($real === false || $root === false || strncmp($real, $root, strlen($root)) !== 0) {
  error_log("outside_log_root real=" . var_export($real,true) . " root=" . var_export($root,true));
  throw new RuntimeException('outside_log_root');
}

    // 3) Llegeix log de forma segura (tail parcial si és gran)
  $maxBytes = 512 * 1024; // 512 KB

  $size = filesize($logPath);
  if ($size === false) {
    throw new RuntimeException('file_stat_failed');
  }

  $fh = fopen($logPath, 'rb');
  if ($fh === false) {
    throw new RuntimeException('file_open_failed');
  }

// NEW: tail opcional via ?tail=N
$tailLines = isset($_GET['tail']) && ctype_digit((string)$_GET['tail']) ? (int)$_GET['tail'] : 0;

if ($tailLines > 0) {
  // Llegeix des del final fins tenir N línies (amb límit de 512KB)
  $buffer = '';
  $chunk = 8192;
  $pos = $size;
  $lines = 0;
  while ($pos > 0 && $lines <= $tailLines) {
    $read = ($pos - $chunk) >= 0 ? $chunk : $pos;
    $pos -= $read;
    fseek($fh, $pos);
    $buffer = fread($fh, $read) . $buffer;
    $lines = substr_count($buffer, "\n");
    if (strlen($buffer) > $maxBytes) {
      $buffer = substr($buffer, -$maxBytes);
      break;
    }
  }
  $parts = ($buffer === '') ? [] : explode("\n", $buffer);
  $buffer = implode("\n", array_slice($parts, -$tailLines));
  $content = $buffer;

  if ($content === '' || $content === false) { $content = "(log buit)"; }

} else {
  // Comportament existent (trim a 512KB si cal)
  if ($size > $maxBytes) {
    fseek($fh, -$maxBytes, SEEK_END);
    $content = "[… trimmed …]\n" . stream_get_contents($fh);
  } else {
    $content = stream_get_contents($fh);
  }
}
fclose($fh);

  // Intenta detectar codificació (evitar soroll si no és utf-8)
  $enc = (function_exists('mb_detect_encoding') ? mb_detect_encoding($content, ['UTF-8','ISO-8859-1','Windows-1252'], true) : 'UTF-8') ?: 'UTF-8';
    if (function_exists('mb_convert_encoding') && strtoupper($enc) !== 'UTF-8') {
      $converted = @mb_convert_encoding($content, 'UTF-8', $enc);
    if (is_string($converted)) { $content = $converted; }
  }

  // 4) Modes de sortida
  if ($mode === 'raw') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    if ($download) {
      header('Content-Disposition: attachment; filename="ia_log_rider_'.$riderId.( $job ? ('_'.$job) : '' ).'.log.txt"');
    }

    echo $content;

    // AUDIT d’èxit (raw)
    try {
      audit_admin(
        $pdo,
        (int)$uid,
        true,
        'view_ia_log',
        $riderId,
        null,
        'admin_logs',
        ['job_uid' => (string)$job, 'log_path' => $real, 'mode' => 'raw', 'download' => $download ? 1 : 0],
        'success',
        null
      );
    } catch (Throwable $e) {
      error_log('audit view_ia_log failed: ' . $e->getMessage());
    }
    exit;
  }

  // ——— AUDIT d’èxit (HTML)
  try {
    audit_admin(
      $pdo,
      (int)$uid,
      true,
      'view_ia_log',
      $riderId,
      null,
      'admin_logs',
      ['job_uid' => (string)$job, 'log_path' => $real, 'mode' => 'html'],
      'success',
      null
    );
  } catch (Throwable $e) {
    error_log('audit view_ia_log failed: ' . $e->getMessage());
  }

  // 5) Render HTML (amb CSP minimal)
  header('Cache-Control: no-store');
  header('Content-Type: text/html; charset=utf-8');
  header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");

  $qs = array_filter([
  'job'   => $job !== '' ? $job : null,
  'rider' => $rider > 0 ? $rider : null,
  'mode'  => 'raw',
  'tail'  => ($tailLines > 0 ? (string)$tailLines : null),
  ], static fn($v) => $v !== null);
  $qsDl = $qs; $qsDl['download'] = '1';

  echo '<!doctype html><meta charset="utf-8">';
  echo '<style>body{font:14px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;padding:16px;background:#0b0d10;color:#d8dee9}';
  echo 'a{color:#8fbcbb} pre{white-space:pre-wrap;word-wrap:break-word;background:#11151a;padding:12px;border-radius:6px;max-height:80vh;overflow:auto} .muted{opacity:.7}</style>';
  echo '<h3>Log IA — Rider #'.h((string)$riderId).'</h3>';
  echo '<div class="muted" style="margin-bottom:12px">'.h($real).'</div>';
  echo '<p>';
  echo '<a href="?'.h(http_build_query($qs)).'">Veure en cru (raw)</a>';
  echo ' · <a href="?'.h(http_build_query($qsDl)).'">Descarrega</a>';
  echo '</p>';
  echo '<pre>'.h($content).'</pre>';

} catch (Throwable $e) {
  // ——— AUDIT d’error (assegura $pdo)
  try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
      require_once dirname(__DIR__) . '/db.php';
      $pdo = db();
    }
    audit_admin(
      $pdo,
      (int)($_SESSION['user_id'] ?? 0),
      true,
      'view_ia_log',
      null,
      null,
      'admin_logs',
      [
        'job_uid' => isset($job) ? (string)$job : '',
        'rider'   => isset($rider) ? (int)$rider : null,
        'error'   => $e->getMessage()
      ],
      'error',
      'log not found'
    );
  } catch (Throwable $e2) {
    error_log('audit view_ia_log error failed: ' . $e2->getMessage());
  }

  http_response_code(404);
  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><meta charset="utf-8"><pre style="padding:1rem">';
  echo 'ERROR: ' . h($e->getMessage());
  echo '</pre>';
}