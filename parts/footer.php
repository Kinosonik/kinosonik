</main>
<?php
if (!defined('APP_LOADED')) { http_response_code(403); exit; }
// Assegurem la versió per a cache-busting
$versio_web = $GLOBALS['versio_web'] ?? '0.0';
?>
<div class="container-fluid">
  <footer class="d-flex flex-wrap justify-content-center align-items-center py-3 mt-0 border-top"
          style="font-size: 0.85rem;">
    <!-- Text -->
    <p class="col-md-auto mb-0 text-body-tertiary small">
      &copy; <?= h(date('Y')) ?> Kinosonik — <?= __('footer.core_dev') ?>
      &nbsp;<i class="bi bi-heart cor-bounce" aria-hidden="true"></i>&nbsp;
      <span class="visually-hidden"><?= __('footer.made_with_love') ?></span>
      <?= __('footer.made_in') ?> · <?= __('footer.version') ?> <?= h($versio_web) ?>
    </p>

    <!-- Enllaç centrat a sota -->
    <p class="w-100 text-center mt-2 mb-0">
      <?php if ((int)($_SESSION['user_id'] ?? 0) > 0): ?>
        <a href="#" class="link-secondary" data-bs-toggle="modal" data-bs-target="#feedbackModal" style="text-decoration: none;">
          <?= h(__('feedback.open') ?: 'Envia comentari/feedback') ?>
        </a>
      <?php endif; ?>
    </p>
  </footer>
</div>

</body>
<?php include __DIR__ . "/feedback_modal.php"; ?>
<!-- Scripts -->
<script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script defer src="<?= h(asset('js/propi.js')) ?>?v=<?= urlencode($versio_web) ?>&t=<?= time() ?>"></script>
<script>
  // Tooltips (evitem duplicats)
  document.addEventListener("DOMContentLoaded", function () {
    if (window.__tooltipsInit) return;
    window.__tooltipsInit = true;
    document.querySelectorAll('[data-bs-toggle="tooltip"]')
      .forEach(el => new bootstrap.Tooltip(el, { delay: { show: 800, hide: 100 } }));
  });

  // Feedback "Copiat!" pels botons de copiar enllaç
  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('.copy-link-btn');
    if (!btn) return;

    const uid = btn.getAttribute('data-uid');
    if (!uid) return;

    const basePath = "<?= rtrim(BASE_PATH, '/') ?>/";
    const absolute = window.location.origin + basePath + "visualitza.php?ref=" + encodeURIComponent(uid);

    try {
      await navigator.clipboard.writeText(absolute);

      let tip = bootstrap.Tooltip.getInstance(btn);
      if (!tip) {
        tip = new bootstrap.Tooltip(btn, { title: 'Copiar enllaç', trigger: 'manual' });
      }
      tip.setContent({ '.tooltip-inner': 'Copiat!' });
      tip.show();
      setTimeout(() => {
        tip.setContent({ '.tooltip-inner': 'Copiar enllaç' });
        tip.hide();
      }, 1200);
    } catch (e) {
      console.error(e);
      alert('No s’ha pogut copiar el link. Pots copiar-lo manualment:\n' + absolute);
    }
  });

  // Netegem la URL de paràmetres volàtils
  (function () {
    const DROP = ['error', 'success', 'modal', 'email', 'return'];
    const url = new URL(window.location.href);
    let changed = false;
    DROP.forEach(k => {
      if (url.searchParams.has(k)) {
        url.searchParams.delete(k);
        changed = true;
      }
    });
    if (changed) {
      const clean = url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') + url.hash;
      history.replaceState({}, '', clean);
    }
  })();
</script>