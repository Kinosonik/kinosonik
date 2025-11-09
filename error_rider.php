<?php
// error_rider.php — pàgina d’error per a referències de riders
declare(strict_types=1);
require_once __DIR__ . '/php/preload.php';
require_once __DIR__ . '/php/i18n.php';

header('Content-Type: text/html; charset=utf-8');

// Helper d’escapat
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Evitem caché
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$type = (string)($_GET['error'] ?? '');
$title = t('error.unknown_title', 'Error desconegut');
$message = t('error.unknown_message', "S'ha produït un error inesperat.");
$http = 400;

switch ($type) {
  case 'no_existeix':
    $title = t('error.no_existeix.title', 'Referència inexistent');
    $message = t('error.no_existeix.msg', "La referència indicada no existeix o no s’ha proporcionat.");
    $http = 404;
    break;
  case 'no_caducada':
    $title = t('error.no_caducada.title', 'Referència caducada');
    $message = t('error.no_caducada.msg', "La referència indicada ja no és vàlida.");
    $http = 410;
    break;
  default:
    // Manté per defecte
    break;
}

http_response_code($http);
?>
<!DOCTYPE html>
<html lang="<?= h(current_lang() ?? 'ca') ?>" data-bs-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="no-referrer">
  <title><?= h($title) ?> — <?= h(t('error.rider_title', 'Error Rider')) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">

<noscript>
  <div class="container py-5">
    <div class="alert alert-danger shadow-sm">
      <strong><?= h($title) ?>:</strong> <?= h($message) ?>
      <div class="mt-2">
        <a href="<?= h(BASE_PATH) ?>index.php" class="btn btn-secondary btn-sm"><?= h(t('btn.back_home', "Torna a l'inici")) ?></a>
      </div>
    </div>
  </div>
</noscript>

<div class="modal fade" id="errorModal" tabindex="-1" aria-labelledby="errorModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content  liquid-glass-kinosonik border-danger" role="alertdialog" aria-modal="true" aria-describedby="errorModalDesc">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title fw-bold" id="errorModalLabel"><?= h($title) ?></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= h(t('btn.close', 'Tanca')) ?>"></button>
      </div>
      <div class="modal-body text-center" id="errorModalDesc">
        <p class="mb-0"><?= h($message) ?></p>
      </div>
      <div class="modal-footer">
        <a href="<?= h(BASE_PATH) ?>index.php" class="btn btn-secondary" rel="noopener">
          <?= h(t('btn.back_home', "Torna a l'inici")) ?>
        </a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
  const el = document.getElementById('errorModal');
  if (!el) return;
  const modal = new bootstrap.Modal(el, { backdrop: 'static' });
  modal.show();
  const closeBtn = el.querySelector('.btn-close');
  if (closeBtn) { try { closeBtn.focus(); } catch(_) {} }
});
</script>

</body>
</html>