<?php
// parts/head.php — meta + CSS globals (sense redefinir helpers)
// IMPORTANT: confiem en php/config.php per a BASE_PATH, asset(), absolute_url(), i18n, etc.
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Normalment preload.php ja carrega config.php; per si de cas:
@require_once __DIR__ . '/../php/config.php';
@require_once __DIR__ . '/../php/i18n.php';

// QS helpers (per ús en index/altres)
$qs_error   = $_GET['error']   ?? '';
$qs_success = $_GET['success'] ?? '';
$qs_modal   = $_GET['modal']   ?? '';

// Idioma UI
$lang   = $_SESSION['lang'] ?? 'ca';
$seccio = $seccio ?? ($_GET['seccio'] ?? '');

// Versió d’actius (per cache-busting)
$VER = (string)($GLOBALS['versio_web'] ?? '0');
?>
<!doctype html>
<html lang="<?= h($lang) ?>" data-bs-theme="dark">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- SEO bàsic -->
    <meta name="description" content="Verifica i segella el teu rider tècnic amb IA i revisió professional.">
    <meta name="author" content="Kinosonik Riders">

    <!-- Open Graph / Social -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Kinosonik Riders">
    <meta property="og:title" content="Kinosonik Riders — Verificació de riders amb IA">
    <meta property="og:description" content="Anàlisi amb IA, verificació tècnica i segell amb seguiment.">
    <meta property="og:image" content="<?= h(asset('img/logo.png')) ?>">
    <meta property="og:url" content="<?= h(absolute_url('')) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@kinosonik">
    <meta name="csrf-token" content="<?= h($_SESSION['csrf'] ?? '') ?>">

    <!-- Icons -->
    <link rel="icon" href="<?= h(asset('img/favicon/favicon.ico')) ?>">
    <link rel="apple-touch-icon" href="<?= h(asset('img/favicon/apple-touch-icon.png')) ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= h(asset('img/favicon/favicon-32x32.png')) ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= h(asset('img/favicon/favicon-16x16.png')) ?>">
    <meta name="theme-color" content="#0b0c0e">

    <!-- CSS (Bootstrap primer, després Icons, després el teu propi.css) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
          rel="stylesheet" crossorigin="anonymous">
    <?php
// helper segur: URL absoluta amb BASE_PATH i versió per mtime
function assetv(string $rel): string {
  $rel = ltrim($rel, '/');
  $url = rtrim(BASE_PATH, '/').'/'.$rel;

  $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
  $fs = $docroot ? ($docroot.'/'.$rel) : '';
  if ($fs && is_file($fs)) {
    $ver = (string)filemtime($fs);
  } else {
    $ver = (string)($GLOBALS['versio_web'] ?? '0');
  }
  return $url.'?v='.rawurlencode($ver);
}
?>
<link href="<?= h(assetv('css/propi.css')) ?>" rel="stylesheet">

    <?php if (!empty($canonicalUrl)): ?>
      <link rel="canonical" href="<?= h($canonicalUrl) ?>">
    <?php endif; ?>

    <title>Kinosonik | Riders</title>
  </head>
  <body class="d-flex flex-column min-vh-100"
        data-bs-spy="scroll"
        data-bs-target="#navbar-menu"
        data-bs-smooth-scroll="true"
        tabindex="0">
    <main class="flex-grow-1">