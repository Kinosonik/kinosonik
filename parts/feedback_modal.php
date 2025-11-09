<?php
// parts/feedback_modal.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = (string)($_SESSION['csrf']);

$uid   = (int)($_SESSION['user_id'] ?? 0);
$email = (string)($_SESSION['email'] ?? '');
$nom   = trim((string)($_SESSION['nom'] ?? ''));
$cogn  = trim((string)($_SESSION['cognoms'] ?? ''));
$nomComplert = trim($nom . ' ' . $cogn);
$csrf  = (string)($_SESSION['csrf'] ?? '');
$enabled = $uid > 0;
?>
<div class="modal fade" id="feedbackModal" tabindex="-1" aria-labelledby="feedbackTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content liquid-glass-kinosonik text-light">
      <div class="modal-header border-secondary">
        <h5 class="modal-title" id="feedbackTitle"><?= h(__('feedback.title') ?: 'Enviar feedback / informar d’un problema') ?></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= h(__('common.tanca') ?: 'Tanca') ?>"></button>
      </div>

      <div class="modal-body">
        <?php if (!$enabled): ?>
          <div class="alert alert-warning">
            <?= h(__('feedback.login_required') ?: 'Has d’iniciar sessió per enviar feedback.') ?>
          </div>
        <?php else: ?>
        <form id="feedbackForm" novalidate>
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <div class="row g-3">
            <div class="col-md-4">
              <label for="fbType" class="form-label"><?= h(__('feedback.type') ?: 'Tipus de missatge') ?></label>
              <select id="fbType" name="type" class="form-select" required>
                <?php
                $opts = [
                  'translation_error' => 'Error de traducció',
                  'suggestion'        => 'Suggerència',
                  'bug'               => 'Error de programació',
                  'idea'              => 'Idea',
                  'proposal'          => 'Proposta',
                  'other'             => 'Altres',
                ];
                foreach ($opts as $v => $lbl) {
                  echo '<option value="'.h($v).'">'.h(__("feedback.type.$v") ?: $lbl).'</option>';
                }
                ?>
              </select>
              <div class="invalid-feedback"><?= h(__('feedback.type_required') ?: 'Selecciona un tipus') ?></div>
            </div>

            <div class="col-12">
              <label for="fbMsg" class="form-label"><?= h(__('feedback.message') ?: 'Missatge') ?></label>
              <textarea id="fbMsg" name="message" class="form-control" rows="6" minlength="10" maxlength="5000" required
                placeholder="<?= h(__('feedback.placeholder') ?: 'Explica breument el problema o la proposta…') ?>"></textarea>
              <div class="invalid-feedback"><?= h(__('feedback.message_required') ?: 'Escriu el missatge (mínim 10 caràcters)') ?></div>
            </div>
          </div>
        </form>
        <?php endif; ?>
      </div>

      <div class="modal-footer border-secondary">
        <div class="me-auto small text-secondary" id="fbMetaHelp">
          <?= h(__('feedback.meta_note') ?: 'S’enviarà informació tècnica (hora, URL, navegador) per ajudar a diagnosticar.') ?>
          <span class="text-muted d-block">
            <?= h(__('feedback.identity_note') ?: 'La identitat (nom, email i UID) s’obté automàticament del teu compte.') ?>
          </span>
        </div>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
          <?= h(__('common.cancel') ?: 'Cancel·la') ?>
        </button>
        <?php if ($enabled): ?>
        <button id="fbSendBtn" type="button" class="btn btn-primary">
          <span class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true"></span>
          <span><?= h(__('feedback.send') ?: 'Envia') ?></span>
        </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php
$basePath = defined('BASE_PATH') ? BASE_PATH : (function_exists('base_path') ? base_path() : '/');
$fbUrl = rtrim($basePath, '/') . '/feedback_submit.php'; // ← wrapper públic
?>
<?php if ($enabled): ?>
<script>
(() => {
  const form = document.getElementById('feedbackForm');
  const btn  = document.getElementById('fbSendBtn');
  const spin = btn?.querySelector('.spinner-border');
  const FEEDBACK_URL = <?= json_encode($fbUrl, JSON_UNESCAPED_SLASHES) ?>;

  function setBusy(b){
    if (!btn) return;
    btn.disabled = !!b;
    if (spin) spin.classList.toggle('d-none', !b);
  }

  btn?.addEventListener('click', async (ev) => {
    ev.preventDefault();
    if (!form) return;
    if (!form.checkValidity()) { form.classList.add('was-validated'); return; }

    setBusy(true);
    try {
      const fd = new FormData(form);
      fd.append('context_url', location.href);
      fd.append('context_title', document.title);

      const r = await fetch(FEEDBACK_URL, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      });

      const ct = r.headers.get('content-type') || '';
      const isJson = ct.includes('application/json');
      const payload = isJson ? await r.json() : { ok:false, error:'non_json' };

      if (r.ok && payload?.ok) {
        form.reset();
        form.classList.remove('was-validated');
        alert(<?= json_encode(__('feedback.thanks') ?: 'Gràcies! Hem rebut el teu missatge.', JSON_UNESCAPED_UNICODE) ?>);
        bootstrap.Modal.getInstance(document.getElementById('feedbackModal'))?.hide();
      } else {
        const msg = (payload && payload.error) || <?= json_encode(__('feedback.error_generic') ?: 'No s’ha pogut enviar el missatge.', JSON_UNESCAPED_UNICODE) ?>;
        alert(msg);
      }
    } catch (e) {
      console.error('[feedback] fetch failed', e);
      alert(<?= json_encode(__('iamsg.error_xarxa') ?: 'Error de xarxa.', JSON_UNESCAPED_UNICODE) ?>);
    } finally {
      setBusy(false);
    }
  });

  console.debug('[feedback] ready', { url: FEEDBACK_URL, form: !!form, btn: !!btn });
})();
</script>
<?php endif; ?>