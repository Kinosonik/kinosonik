<?php
// php/cron_status.php — estat darrera execució i interval del cron
declare(strict_types=1);
// Opcional: require preload/session si necessites auth; si no, endpoint públic:
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$heartbeat = sys_get_temp_dir() . '/ks_cron_heartbeat.json';
$intervalDefault = 300; // 5 min

$last = 0;
$interval = $intervalDefault;

if (is_file($heartbeat)) {
  $raw = @file_get_contents($heartbeat);
  if ($raw !== false) {
    $j = json_decode($raw, true);
    if (is_array($j)) {
      $last     = (int)($j['last'] ?? 0);
      $interval = (int)($j['interval'] ?? $intervalDefault);
    }
  }
}
if ($interval < 60) $interval = 60; // seguretat
if ($last <= 0)     $last     = time();

$next = $last + $interval;

echo json_encode([
  'ok'            => true,
  'last_ts'       => $last,
  'last_iso'      => gmdate('c', $last),
  'interval_s'    => $interval,
  'next_ts'       => $next,
  'next_iso'      => gmdate('c', $next),
  'server_time_iso'=> gmdate('c'),
], JSON_UNESCAPED_SLASHES);