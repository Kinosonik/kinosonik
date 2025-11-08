<?php
// php/flash.php — helpers per a missatges flash d'una sola vista
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!function_exists('flash_set')) {
  /** Desa un missatge flash (només clau + tipus; el text es resol per i18n). */
  function flash_set(string $type, string $key, array $extra = []): void {
    $_SESSION['flash'] = ['type' => $type, 'key' => $key, 'extra' => $extra, 'ts' => time()];
  }
}

if (!function_exists('flash_get')) {
  /** Llegeix i consumeix el flash (o null si no n'hi ha). */
  function flash_get(): ?array {
    if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
  }
}