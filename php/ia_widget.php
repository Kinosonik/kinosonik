<?php
// php/ia_widget.php — resum compacte d’estat IA (admin)
declare(strict_types=1);

$LOG = '/var/config/logs/riders/worker.log';
$WINDOW_MIN = 10;

/* Helpers locals robustos */
if (!function_exists('tail_last_bytes')) {
  function tail_last_bytes(string $file, int $bytes = 65536): string {
    if (!is_file($file) || !is_readable($file)) return '';
    $size = @filesize($file);
    if ($size === false || $size <= 0) return '';
    $bytes = max(1, min($bytes, (int)$size));
    $fh = @fopen($file, 'rb');
    if ($fh === false) return '';
    @fseek($fh, -$bytes, SEEK_END);
    $chunk = @stream_get_contents($fh);
    @fclose($fh);
    return (string)$chunk;
  }
}
if (!function_exists('tail_lines')) {
  function tail_lines(string $file, int $maxLines = 800): array {
    $buf = tail_last_bytes($file, 65536);
    if ($buf === '') return [];
    $lines = array_filter(array_map('trim', explode("\n", $buf)));
    $n = count($lines);
    return $n <= $maxLines ? $lines : array_slice($lines, $n - $maxLines);
  }
}
if (!function_exists('safe')) {
  function safe($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('dt_eu')) {
  /**
   * Converteix un ISO (normalment UTC/Z) a Europe/Madrid i el formata.
   * Accepta DateTimeInterface o string; fallback a '—'.
   */
  function dt_eu($dt, string $fmt = 'd/m/Y H:i'): string {
    try {
      if ($dt instanceof DateTimeInterface) {
        $d = $dt;
      } elseif (is_string($dt) && $dt !== '') {
        // Si el text no porta TZ, assumeix UTC; si la porta, respecta-la
        $hasTz = (bool)preg_match('/[Zz]|[+\-]\d{2}:\d{2}$/', $dt);
        $d = new DateTimeImmutable($dt, $hasTz ? null : new DateTimeZone('UTC'));
      } else {
        return '—';
      }
      return $d->setTimezone(new DateTimeZone('Europe/Madrid'))->format($fmt);
    } catch (Throwable $e) {
      return '—';
    }
  }
}


/* Si el log no és llegible: targeta curta i sortim */
if (!is_readable($LOG)) {
  echo '<div class="card text-bg-dark my-2"><div class="card-body py-2 fw-lighter">'
     . '<i class="bi bi-exclamation-circle text-warning me-2"></i>'
     . 'Sense accés a <code>' . safe($LOG) . '</code>'
     . '</div></div>';
  return;
}

/* Parse ràpid del tram final de log */
$lines = tail_lines($LOG, 1200);
$lastTick = null; $lastQueue = null; $lastErr = null;
$now = time(); $sinceTs = $now - ($WINDOW_MIN * 60);
$doneInWindow = 0;

foreach (array_reverse($lines) as $ln) {
  $j = json_decode($ln, true);
  if (!is_array($j) || !isset($j['event'])) continue;

  if ($lastTick === null && $j['event'] === 'tick_end') $lastTick = $j;
  if ($lastQueue === null && $j['event'] === 'queue_scan') $lastQueue = $j;
  if ($lastErr === null && (($j['lvl'] ?? '') === 'error')) $lastErr = $j;

  if ($j['event'] === 'job_done' && isset($j['ts'])) {
    $t = strtotime($j['ts']);
    if ($t !== false && $t >= $sinceTs) $doneInWindow++;
  }
}

$queueLen   = (int)($lastQueue['queue_len'] ?? 0);
$jobsPerMin = $WINDOW_MIN > 0 ? round($doneInWindow / $WINDOW_MIN, 2) : 0.0;
?>
<div class="row g-3 my-2 fw-lighter">
  <div class="col-sm-6 col-lg-3">
    <div class="card text-bg-dark h-100">
      <div class="card-body">
        <div class="mb-1">Darrer tick</div>
        <div class="small">hora:
          <code><?= $lastTick ? safe(dt_eu($lastTick['ts'])) : '—' ?></code>
        </div>
        <div class="small">processats:
          <span class="badge bg-secondary"><?= (int)($lastTick['processed'] ?? 0) ?></span>
        </div>
        <div class="small">durada:
          <span class="badge bg-secondary"><?= (int)($lastTick['duration_ms'] ?? 0) ?> ms</span>
        </div>
        <div class="small">rss:
          <span class="badge bg-secondary"><?= (int)($lastTick['rss_mb'] ?? 0) ?> MB</span>
        </div>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-lg-3">
  <div class="card text-bg-dark h-100">
    <div class="card-body">
      <div class="mb-1">Cua</div>
      <div style="font-size:1.6rem" class="fw-bold"><?= (int)$queueLen ?></div>
      <div class="small text-muted">
        darrer scan: <code><?= $lastQueue ? safe(dt_eu($lastQueue['ts'])) : '—' ?></code>
      </div>
    </div>
  </div>
</div>

  <?php $jobsPerMin = $WINDOW_MIN > 0 ? $doneInWindow / $WINDOW_MIN : 0.0; ?>
<div class="col-sm-6 col-lg-3">
  <div class="card text-bg-dark h-100">
    <div class="card-body">
      <div class="mb-1">Ritme</div>
      <div class="fw-bold" style="font-size:1.2rem"><?= number_format($jobsPerMin, 2) ?> jobs/min</div>
      <div class="small text-muted">finestra: <?= (int)$WINDOW_MIN ?> min</div>
    </div>
  </div>
</div>

  <div class="col-sm-6 col-lg-3">
  <div class="card text-bg-dark h-100">
    <div class="card-body">
      <div class="mb-1">Darrer error</div>
      <?php if ($lastErr): ?>
        <div class="small">hora: <code><?= safe(dt_eu($lastErr['ts'])) ?></code></div>
        <?php if (!empty($lastErr['event'])): ?>
          <div class="small">event: <code><?= safe($lastErr['event']) ?></code></div>
        <?php endif; ?>
        <?php if (!empty($lastErr['job_uid'])): ?>
          <div class="small">job: <code><?= safe($lastErr['job_uid']) ?></code></div>
        <?php endif; ?>
        <?php if (!empty($lastErr['message'])): ?>
          <div class="small text-warning-emphasis"><?= safe($lastErr['message']) ?></div>
        <?php endif; ?>
      <?php else: ?>
        <div class="small text-success">cap error recent</div>
      <?php endif; ?>
    </div>
  </div>
</div>