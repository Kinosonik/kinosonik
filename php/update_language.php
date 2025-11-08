<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';      // normalize_lang(), set_lang(), current_lang()
require_once __DIR__ . '/middleware.php';

$pdo = db();

if (!is_post()) { http_response_code(405); exit; }
csrf_check_or_die();

/* ── Guard: cal estar loguejat ──────────────────────────────────────────── */
if (empty($_SESSION['loggedin']) || empty($_SESSION['user_id'])) {
  $ret = BASE_PATH . 'dades.php';
  header('Location: ' . BASE_PATH . 'index.php?modal=login&return=' . rawurlencode($ret), true, 302);
  exit;
}

/* ── Inputs ─────────────────────────────────────────────────────────────── */
$idiomaRaw = $_POST['idioma'] ?? null;
if (!is_string($idiomaRaw) || $idiomaRaw === '') {
  // No forcem 'ca' per defecte si el formulari no envia res
  $_SESSION['flash'] = [['type' => 'danger', 'msg' => 'Idioma no vàlid.']];
  header('Location: ' . BASE_PATH . 'dades.php', true, 303);
  exit;
}
$idioma = normalize_lang($idiomaRaw);

$return_to = (string)($_POST['return_to'] ?? (BASE_PATH . 'dades.php'));

/* ── Normalitza return_to per encabir BASE_PATH i netejar params del modal ─ */
$parsed = parse_url($return_to);
$path   = '/' . ltrim((string)($parsed['path'] ?? '/'), '/');
$base   = rtrim(BASE_PATH, '/');
if ($base !== '' && $base !== '/' && !str_starts_with($path, $base . '/')) {
  $path = $base . $path;
}
$query = [];
if (!empty($parsed['query'])) { parse_str($parsed['query'], $query); }
unset($query['modal'], $query['error'], $query['success']);
$qs   = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
$frag = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
$return_to_clean = $path . ($qs ? ('?' . $qs) : '') . $frag;

/* ── Persistència ───────────────────────────────────────────────────────── */
try {
  $uid = (int)$_SESSION['user_id'];

  // Desa a DB
  $st = $pdo->prepare("UPDATE Usuaris SET Idioma = :lang WHERE ID_Usuari = :id");
  $st->execute([':lang' => $idioma, ':id' => $uid]);

  // Desa a sessió + cookie
  set_lang($idioma);

  $_SESSION['flash_success'] = 'updated';

  // Redirecció a la pàgina d’origen amb success=updated
  $sep = str_contains($return_to_clean, '?') ? '&' : '?';
  header('Location: ' . $return_to_clean . $sep . 'success=updated', true, 303);
  exit;

} catch (Throwable $e) {
  error_log('update_language error: ' . $e->getMessage());
  $_SESSION['flash'] = [['type' => 'danger', 'msg' => 'Error intern en actualitzar l’idioma.']];
  header('Location: ' . $return_to_clean, true, 303);
  exit;
}