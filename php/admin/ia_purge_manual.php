<?php
// php/admin/ia_purge_manual.php — Purga manual de logs IA (només ADMIN)
declare(strict_types=1);

require_once dirname(__DIR__) . '/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once dirname(__DIR__) . '/db.php';

if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function fail(string $m, int $code=400): never {
  http_response_code($code);
  echo '<div class="container my-4"><div class="alert alert-danger">'.$m.'</div></div>';
  exit;
}

// ── Mètode + CSRF
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') fail('Method not allowed', 405);
$csrf = (string)($_POST['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) fail('CSRF invàlid', 403);

// ── Només ADMIN
$uid = $_SESSION['user_id'] ?? null;
if (!$uid) { header('Location: '.BASE_PATH.'index.php?error=login_required'); exit; }
$pdo = db();
$st = $pdo->prepare('SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari=? LIMIT 1');
$st->execute([$uid]);
$role = (string)($st->fetchColumn() ?: '');
if (strcasecmp($role, 'admin') !== 0) {
  header('Location: '.BASE_PATH.'espai.php?seccio=dades&error=forbidden'); exit;
}

// ── Paràmetres de purga
$logsDir   = rtrim((string)($GLOBALS['KS_SECURE_LOG_DIR'] ?? '/var/config/logs/riders'), '/').'/ia';
$tmpGlob   = '/tmp/ai-*.json';

// Permet ajustar dies via POST (només numèrics i segurs)
$daysInput = (int)($_POST['days'] ?? 60);
$daysLogs  = max(1, min(365, $daysInput)); // límit: 1–365 dies
$daysTmp   = 1;  // sempre purga /tmp de més d’un dia

$now = time();
$cutLogs = $now - ($daysLogs * 86400);
$cutTmp  = $now - ($daysTmp  * 86400);

$removed = 0;
$reclaimed = 0;
$checked = 0;
$errors = [];

// ── Purga logs IA antics
if (is_dir($logsDir)) {
  $it = @scandir($logsDir);
  if ($it !== false) {
    foreach ($it as $f) {
      if ($f === '.' || $f === '..') continue;
      if (!preg_match('/^run_[a-z0-9-]+\.log$/i', $f)) continue;
      $path = $logsDir.'/'.$f;
      if (!is_file($path)) continue;
      $checked++;
      $mtime = @filemtime($path) ?: $now;
      if ($mtime < $cutLogs) {
        $size = @filesize($path) ?: 0;
        if (@unlink($path)) { $removed++; $reclaimed += $size; }
        else { $errors[] = "No s'ha pogut esborrar: $path"; }
      }
    }
  }
} else {
  $errors[] = "No existeix el directori de logs: $logsDir";
}

// ── Purga estat temporal /tmp/ai-*.json antic
$tmpRemoved = 0;
foreach (glob($tmpGlob) ?: [] as $t) {
  if (!is_file($t)) continue;
  $mtime = @filemtime($t) ?: $now;
  if ($mtime < $cutTmp) {
    if (@unlink($t)) $tmpRemoved++;
    else $errors[] = "No s'ha pogut esborrar: $t";
  }
}

// ── Resultats
$kb = $reclaimed > 0 ? number_format($reclaimed/1024, 0, ',', '.') : '0';
?>
<div class="container my-4" style="max-width:720px;">
  <div class="alert alert-success">
    <div class="fw-semibold mb-1">Purga completada</div>
    <ul class="mb-0">
      <li>Fitxers revisats: <?= h((string)$checked) ?></li>
      <li>Logs esborrats (&gt;<?= h((string)$daysLogs) ?> dies): <strong><?= h((string)$removed) ?></strong></li>
      <li>Estats temporals /tmp esborrats (&gt;<?= h((string)$daysTmp) ?> dia): <strong><?= h((string)$tmpRemoved) ?></strong></li>
      <li>Espai recuperat: <strong><?= h($kb) ?> KB</strong></li>
    </ul>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-warning">
      <div class="fw-semibold mb-1">Incidències:</div>
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?>
          <li><?= h($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="POST" action="<?= h(BASE_PATH) ?>php/admin/ia_purge_manual.php" class="mt-3 d-flex align-items-end gap-2">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
    <div>
      <label class="form-label small mb-0">Purga logs de més de (dies):</label>
      <input type="number" class="form-control form-control-sm" name="days" value="<?= h((string)$daysLogs) ?>" min="1" max="365" style="width:100px;">
    </div>
    <button type="submit" class="btn btn-outline-danger btn-sm mb-1">
      <i class="bi bi-trash-3"></i> Torna a netejar
    </button>
    <a href="<?= h(BASE_PATH) ?>espai.php?seccio=admin_logs" class="btn btn-primary btn-sm mb-1">
      <i class="bi bi-arrow-left"></i> Torna a Logs IA
    </a>
  </form>
</div>