<?php
// php/ia_status.php — Badge flotant IA amb countdown + polling lleuger
declare(strict_types=1);

// CONFIG: ajusta segons el teu entorn
$LOG = '/var/config/logs/riders/worker.log';   // NDJSON amb events "tick_end"
$INTERVAL_SEC = 120;                            // el teu cron és cada 2 minuts
$MAX_BYTES = 65536;                             // bytes a llegir del final del log

// --- helpers ---
function ia_tail_last_bytes(string $file, int $bytes): string {
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

/** Retorna el timestamp (epoch) del darrer tick_end (UTC) o null */
function ia_last_tick_end_epoch(string $log): ?int {
  $buf = ia_tail_last_bytes($log, $GLOBALS['MAX_BYTES']);
  if ($buf === '') return null;
  $lines = array_reverse(array_filter(array_map('trim', explode("\n", $buf))));
  foreach ($lines as $ln) {
    $j = json_decode($ln, true);
    if (is_array($j) && ($j['event'] ?? '') === 'tick_end' && !empty($j['ts'])) {
      try {
        $dt = new DateTimeImmutable((string)$j['ts']); // ISO 8601 Z
        return $dt->getTimestamp();
      } catch (Throwable $e) { /* ignore */ }
    }
  }
  return null;
}

// --- JSON mode (per polling) ---
if (isset($_GET['fmt']) && $_GET['fmt'] === 'json') {
  $last = ia_last_tick_end_epoch($LOG);
  // cap header si ja s’ha enviat output (per seguretat, evitem Warnings)
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
  }
  echo json_encode([
    'ok' => ($last !== null),
    'last_epoch' => $last,
    'interval_sec' => $INTERVAL_SEC,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// --- HTML mode (badge + JS) ---
$last = ia_last_tick_end_epoch($LOG);
// Si no hi ha cap tick encara, no pintem res (silenciós)
if (!$last) return;

// (Opcional) mostra també l’hora local “darrera execució”
$whenLocal = '—';
try {
  $tz = new DateTimeZone('Europe/Madrid');
  $whenLocal = (new DateTimeImmutable("@$last"))->setTimezone($tz)->format('d/m/Y H:i');
} catch (Throwable $e) { /* ignore */ }
?>

<div class="ia-badge" id="iaBadge"
     data-last="<?= (int)$last ?>"
     data-interval="<?= (int)$INTERVAL_SEC ?>"
     data-endpoint="<?= htmlspecialchars((rtrim((string)(defined('BASE_PATH') ? BASE_PATH : '/'), '/')).'/php/ia_status.php?fmt=json', ENT_QUOTES, 'UTF-8') ?>">
  <i class="bi bi-robot"></i><?= htmlspecialchars($whenLocal, ENT_QUOTES, 'UTF-8') ?> <i class="bi bi-clock-history"></i><span style="width: 20px; text-align:center;" id="iaRemain">…</span>
</div>

<script>
(function(){
  const el = document.getElementById('iaBadge'); if (!el) return;
  const spR = document.getElementById('iaRemain');

  let last = Number(el.getAttribute('data-last')||0);
  let interval = Math.max(1, Number(el.getAttribute('data-interval')||120));
  const endpoint = el.getAttribute('data-endpoint') || '';

  function recolor(remain, elapsed){
    if (elapsed > interval * 2)      el.style.background = 'rgba(220,53,69,.18)';   // vermell (stale)
    else if (elapsed > interval)     el.style.background = 'rgba(255,193,7,.18)';   // groc (tard)
    else                             el.style.background = 'rgba(13,110,253,.14)';  // normal (blau)
  }

  function tick(){
    const now = Math.floor(Date.now()/1000);
    const elapsed = Math.max(0, now - last);
    let remain = Math.max(0, (last + interval) - now);
    if (spR) spR.textContent = String(remain);
    recolor(remain, elapsed);

    // si hem arribat a 0, forcem un refresh immediat
    if (remain === 0) poll(true);
  }

  let fetching = false;
  async function poll(force=false){
    if (!endpoint || fetching) return;
    fetching = true;
    try {
      const resp = await fetch(endpoint, { cache: 'no-store' });
      const json = await resp.json().catch(()=>null);
      if (json && json.ok) {
        // si el servidor diu que hi ha tick nou o l'interval ha canviat → reseteja
        if (typeof json.last_epoch === 'number' && json.last_epoch > last) {
          last = json.last_epoch;
        }
        if (typeof json.interval_sec === 'number' && json.interval_sec > 0) {
          interval = json.interval_sec;
        }
      }
    } catch(_) {/* silenciós */}
    finally { fetching = false; }
  }

  // refresca comptador cada segon
  tick();
  setInterval(tick, 1000);

  // polling periòdic (lleuger): cada 10 s
  setInterval(() => poll(false), 10000);
})();
</script>