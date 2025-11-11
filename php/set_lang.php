<?php
// php/set_lang.php — canvia l’idioma (sessió + cookie) i el persisteix si l’usuari està loguejat
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/preload.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/i18n.php'; // normalize_lang(), set_lang()
require_once __DIR__ . '/db.php';
$pdo = db();

if (!is_post()) { http_response_code(405); exit; }
csrf_check_or_die();

/* ── Entrada i normalització ───────────────────────────── */
$lang = normalize_lang((string)($_POST['lang'] ?? 'ca'));

/* ── Desa a sessió + cookie (helper unificat) ──────────── */
set_lang($lang);

/* ── Si hi ha sessió iniciada, persisteix a BD ─────────── */
if (!empty($_SESSION['loggedin']) && !empty($_SESSION['user_id'])) {
  try {
    $st = $pdo->prepare("UPDATE Usuaris SET Idioma = :lang WHERE ID_Usuari = :id");
    $st->execute([':lang' => $lang, ':id' => (int)$_SESSION['user_id']]);
  } catch (Throwable $e) {
    error_log('set_lang persist error: ' . $e->getMessage());
  }
}

/* ── Redirecció segura ─────────────────────────────────── */
$fallback = (defined('BASE_PATH') ? BASE_PATH : '/') . 'index.php';
$target   = $fallback;

$ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
$host   = $_SERVER['HTTP_HOST'] ?? '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

/* 🔸 PRIORITAT 1: HTTP_REFERER del mateix origen */
if ($ref !== '') {
  $refParts = @parse_url($ref) ?: [];
  if (($refParts['host'] ?? '') === $host && ($refParts['scheme'] ?? '') === $scheme) {
    $target = $ref;
  }
}

/* 🔸 PRIORITAT 2: si no hi ha referer, usem REQUEST_URI actual (ajuda a pàgines legals) */
if ($ref === '' && !empty($_SERVER['REQUEST_URI'])) {
  $target = $scheme . '://' . $host . $_SERVER['REQUEST_URI'];
}

/* 🔸 PRIORITAT 3: fallback final si tot falla */
if (empty($target)) {
  $target = $fallback;
}

header('Location: ' . $target, true, 302);
exit;
