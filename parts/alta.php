<?php
// parts/alta.php FORMULARI ALTA USUARIS
declare(strict_types=1);
require_once __DIR__ . '/../php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../php/i18n.php';
require_once __DIR__ . '/../php/messages.php';

if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// CSRF sempre present (inofensiu si ja existeix)
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// A qui li direm “error/success”? Fem servir els QS ja sanititzats
$error   = $qs_error   ?? '';
$success = $qs_success ?? '';
?>
<div class="modal fade" id="registre_usuaris" data-bs-backdrop="static" data-bs-keyboard="false"
     tabindex="-1" aria-labelledby="registreLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-kinosonik text-white">
        <h1 class="modal-title fs-5" id="registreLabel"><?= __('register.title') ?></h1>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= __('close') ?>"></button>
      </div>
      <div class="modal-body">

        <!-- Missatges opcionals (traduïts) -->
        <?php if ($error !== '' && isset($messages['error'][$error])): ?>
          <div class="alert alert-danger" role="alert" aria-live="assertive">
            <?= h((string)$messages['error'][$error]) ?>
          </div>
        <?php elseif ($success !== '' && isset($messages['success'][$success])): ?>
          <div class="alert alert-success" role="alert" aria-live="polite">
            <?= h((string)$messages['success'][$success]) ?>
          </div>
        <?php endif; ?>

        <!-- Formulari de registre -->
        <form class="row g-3 needs-validation" id="registreForm"
              action="<?= h(BASE_PATH) ?>php/registre.php"
              method="POST" novalidate>
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
          <input type="hidden" name="return" value="<?= h($_GET['return'] ?? '') ?>">

          <!-- Nom -->
          <div class="col-md-3">
            <label for="nom" class="form-label"><?= __('register.name') ?></label>
            <input type="text" class="form-control" id="nom" name="nom"
                   required pattern="[A-Za-zÀ-ÿ' -]{2,50}"
                   autocomplete="given-name" inputmode="text" autocapitalize="words" spellcheck="false">
            <div class="invalid-feedback"><?= __('register.invalid_name') ?></div>
          </div>

          <!-- Cognoms -->
          <div class="col-md-5">
            <label for="cognoms" class="form-label"><?= __('register.surnames') ?></label>
            <input type="text" class="form-control" id="cognoms" name="cognoms"
                   required pattern="[A-Za-zÀ-ÿ' -]{2,100}"
                   autocomplete="family-name" inputmode="text" autocapitalize="words" spellcheck="false">
            <div class="invalid-feedback"><?= __('register.invalid_surnames') ?></div>
          </div>

          <!-- Telèfon -->
          <div class="col-md-4">
            <label for="telefon" class="form-label"><?= __('register.phone') ?></label>
            <input type="tel" class="form-control" id="telefon" name="telefon"
                   pattern="^\+[1-9][0-9]{6,14}$" required
                   autocomplete="tel" inputmode="tel" autocapitalize="off" spellcheck="false">
            <div class="form-text"><?= __('register.phone_format') ?></div>
            <div class="invalid-feedback"><?= __('register.invalid_phone') ?></div>
          </div>

          <!-- Correu -->
          <div class="col-md-6">
            <label for="email" class="form-label"><?= __('register.email') ?></label>
            <input type="email" class="form-control" id="email" name="email" required
                   autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false">
            <div class="invalid-feedback"><?= __('register.invalid_email') ?></div>
          </div>

          <!-- Tipus d’usuari -->
          <div class="col-md-6">
            <label for="tipus_usuari" class="form-label"><?= __('register.user_type') ?></label>
            <select id="tipus_usuari" name="tipus_usuari" class="form-select" required>
              <option value="" selected disabled><?= __('register.select_option') ?></option>
              <option value="tecnic"><?= __('register.type_tecnic') ?></option>
              <option value="sala"><?= __('register.type_sala') ?></option>
              <!--<option value="productor"><?= __('register.type_productor') ?></option>--> 
              <!--<option value="banda"><?= __('register.type_banda') ?></option>-->
            </select>
            <div class="invalid-feedback"><?= __('register.must_select') ?></div>
          </div>

          <!-- Contrasenya -->
          <div class="col-md-6">
            <label for="password" class="form-label"><?= __('register.password') ?></label>
            <input type="password" class="form-control" id="password" name="password"
                   required minlength="8"
                   pattern="(?=^.{8,}$)(?=.*[A-Za-z])(?=.*\d).*$"
                   aria-describedby="passwordHelp"
                   autocomplete="new-password">
            <div id="passwordHelp" class="form-text"><?= __('register.password_help') ?></div>
            <div class="invalid-feedback"><?= __('register.invalid_password') ?></div>
          </div>

          <!-- Confirmació -->
          <div class="col-md-6">
            <label for="password2" class="form-label"><?= __('register.password_repeat') ?></label>
            <input type="password" class="form-control" id="password2" name="password2"
                   required minlength="8" autocomplete="new-password">
            <div class="invalid-feedback"><?= __('register.password_mismatch') ?></div>
          </div>

          <!-- Política -->
          <div class="col-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" value="1" id="politica" name="politica" required>
              <label class="form-check-label" for="politica">
                <?= __('register.accept_policy') ?> 
                <a href="<?= h(BASE_PATH) ?>legal/politica-privacitat.html" target="_blank" rel="noopener"><?= __('register.policy') ?></a>.
              </label>
              <div class="invalid-feedback"><?= __('register.must_accept_policy') ?></div>
            </div>
            <input type="hidden" name="politica_versio" value="2025-01">
          </div>

          <!-- Botó -->
          <div class="row justify-content-center mt-2">
            <div class="col-4">
              <button id="altaSubmitBtn" class="btn btn-primary w-100" type="submit">
                <span id="altaSpinner" class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true"></span>
                <span id="altaBtnText"
                      data-default="<?= h(__('register.submit')) ?>"
                      data-loading="<?= h(__('loading.processing')) ?>">
                  <?= __('register.submit') ?>
                </span>
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
// Validació Bootstrap + check coincidència + spinner & bloqueig doble submit
(() => {
  'use strict';

  const form    = document.getElementById('registreForm');
  if (!form) return;

  const pass1   = document.getElementById('password');
  const pass2   = document.getElementById('password2');
  const btn     = document.getElementById('altaSubmitBtn');
  const spin    = document.getElementById('altaSpinner');
  const btnText = document.getElementById('altaBtnText');

  let submitting = false;

  form.addEventListener('submit', (event) => {
    if (submitting) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }

    // Validacions pròpies
    pass2.setCustomValidity('');
    if (pass1.value !== pass2.value) {
      pass2.setCustomValidity('<?= h(__('register.password_mismatch')) ?>');
    }

    // Validació Bootstrap
    if (!form.checkValidity()) {
      event.preventDefault();
      event.stopPropagation();
      form.classList.add('was-validated');
      return;
    }

    // Estat “enviant” — NOMÉS el botó. No desactivar inputs (sinó no s'envien).
    submitting = true;
    if (btn) btn.disabled = true;
    if (spin) spin.classList.remove('d-none');
    if (btnText) btnText.textContent = btnText.getAttribute('data-loading') || 'Processing…';

    // (Opcional) si vols “bloquejar” la UI sense perdre valors:
    // - Afegeix readonly als inputs de text/email/tel/password:
    // Array.from(form.elements).forEach(el => {
    //   if (el.tagName === 'INPUT' && ['text','email','tel','password'].includes(el.type)) {
    //     el.readOnly = true;
    //   }
    // });
    //
    // - O bé afegeix una capa CSS amb pointer-events per impedir clics, però sense tocar 'disabled'.

    // Fallback anti-bloqueig (15s) per si el navegador no navega
    setTimeout(() => {
      if (!document.hidden && submitting) {
        submitting = false;
        if (btn) btn.disabled = false;
        if (spin) spin.classList.add('d-none');
        if (btnText) btnText.textContent = btnText.getAttribute('data-default') || '<?= h(__('register.submit')) ?>';
        // No cal reactivar res més perquè no hem desactivat inputs.
      }
    }, 15000);
  }, false);
})();
</script>