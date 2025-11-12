<?php
/**
 * FORMULARI D'ALTA D'USUARIS
 * Modal de registre amb validació client/servidor, honeypot, CSRF i accessibilitat
 * 
 * @version 2.0
 * @requires preload.php, i18n.php, messages.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../php/preload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../php/i18n.php';
require_once __DIR__ . '/../php/messages.php';

// Helper de sanitització HTML
if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// Generar token CSRF (idempotent)
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// Recollir missatges del query string (ja sanititzats per preload)
$error   = $qs_error   ?? '';
$success = $qs_success ?? '';
?>

<!-- ============================================
     MODAL DE REGISTRE D'USUARIS
     ============================================ -->
<div class="modal fade" 
     id="registre_usuaris" 
     data-bs-backdrop="static" 
     data-bs-keyboard="false"
     tabindex="-1" 
     aria-labelledby="registreLabel" 
     aria-hidden="true">
    
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content liquid-glass-kinosonik">
            
            <!-- CAPÇALERA -->
            <div class="modal-header border-0 text-white">
                <h1 class="modal-title fs-5" id="registreLabel">
                    <?= __('register.title') ?>
                </h1>
                <button type="button" 
                        class="btn-close btn-close-white" 
                        data-bs-dismiss="modal" 
                        aria-label="<?= __('close') ?>">
                </button>
            </div>

            <!-- COS DEL MODAL -->
            <div class="modal-body">

                <!-- MISSATGES D'ERROR/ÈXIT -->
                <?php if ($error !== '' && isset($messages['error'][$error])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" 
                         role="alert" 
                         aria-live="assertive">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?= h((string)$messages['error'][$error]) ?>
                        <button type="button" 
                                class="btn-close" 
                                data-bs-dismiss="alert" 
                                aria-label="<?= __('close') ?>">
                        </button>
                    </div>
                <?php elseif ($success !== '' && isset($messages['success'][$success])): ?>
                    <div class="alert alert-success alert-dismissible fade show" 
                         role="alert" 
                         aria-live="polite">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?= h((string)$messages['success'][$success]) ?>
                        <button type="button" 
                                class="btn-close" 
                                data-bs-dismiss="alert" 
                                aria-label="<?= __('close') ?>">
                        </button>
                    </div>
                <?php endif; ?>

                <!-- FORMULARI DE REGISTRE -->
                <form class="row g-3 needs-validation" 
                      id="registreForm"
                      action="<?= h(BASE_PATH) ?>php/registre.php"
                      method="POST" 
                      novalidate>

                    <!-- TOKEN CSRF -->
                    <input type="hidden" 
                           name="csrf" 
                           value="<?= h($_SESSION['csrf'] ?? '') ?>">

                    <!-- URL DE RETORN (opcional) -->
                    <input type="hidden" 
                           name="return" 
                           value="<?= h($_GET['return'] ?? '') ?>">

                    <!-- HONEYPOT ANTI-BOT (camp invisible) -->
                    <input type="text" 
                           name="website" 
                           id="website" 
                           style="position:absolute;left:-9999px;width:1px;height:1px;" 
                           tabindex="-1" 
                           autocomplete="off" 
                           aria-hidden="true"
                           value="">

                    <!-- NOM -->
                    <div class="col-md-3">
                        <label for="nom" class="form-label">
                            <?= __('register.name') ?>
                            <span class="text-danger" aria-label="<?= __('required') ?>">*</span>
                        </label>
                        <input type="text" 
                               class="form-control" 
                               id="nom" 
                               name="nom"
                               required 
                               minlength="2"
                               maxlength="50"
                               pattern="[A-Za-zÀ-ÿ' -]{2,50}"
                               autocomplete="given-name" 
                               inputmode="text" 
                               autocapitalize="words" 
                               spellcheck="false"
                               aria-describedby="nomHelp">
                        <div class="invalid-feedback">
                            <?= __('register.invalid_name') ?>
                        </div>
                    </div>

                    <!-- COGNOMS -->
                    <div class="col-md-5">
                        <label for="cognoms" class="form-label">
                            <?= __('register.surnames') ?>
                            <span class="text-danger" aria-label="<?= __('required') ?>">*</span>
                        </label>
                        <input type="text" 
                               class="form-control" 
                               id="cognoms" 
                               name="cognoms"
                               required 
                               minlength="2"
                               maxlength="100"
                               pattern="[A-Za-zÀ-ÿ' -]{2,100}"
                               autocomplete="family-name" 
                               inputmode="text" 
                               autocapitalize="words" 
                               spellcheck="false">
                        <div class="invalid-feedback">
                            <?= __('register.invalid_surnames') ?>
                        </div>
                    </div>

                    <!-- TELÈFON -->
                    <div class="col-md-4">
                        <label for="telefon" class="form-label">
                            <?= __('register.phone') ?>
                            <span class="text-danger" aria-label="<?= __('required') ?>">*</span>
                        </label>
                        <input type="tel" 
                               class="form-control" 
                               id="telefon" 
                               name="telefon"
                               pattern="^(\+?[1-9][0-9]{6,14}|[0-9]{9,15})$" 
                               required
                               autocomplete="tel" 
                               inputmode="tel" 
                               autocapitalize="off" 
                               spellcheck="false"
                               aria-describedby="telefonHelp">
                        <div id="telefonHelp" class="form-text">
                            <?= __('register.phone_format') ?>
                        </div>
                        <div class="invalid-feedback">
                            <?= __('register.invalid_phone') ?>
                        </div>
                    </div>

                    <!-- CORREU ELECTRÒNIC -->
                    <div class="col-md-6">
                        <label for="email" class="form-label">
                            <?= __('register.email') ?>
                            <span class="text-danger" aria-label="<?= __('required') ?>">*</span>
                        </label>
                        <input type="email" 
                               class="form-control" 
                               id="email" 
                               name="email" 
                               required
                               maxlength="255"
                               autocomplete="email" 
                               inputmode="email" 
                               autocapitalize="off" 
                               spellcheck="false">
                        <div class="invalid-feedback">
                            <?= __('register.invalid_email') ?>
                        </div>
                    </div>

                    <!-- TIPUS D'USUARI -->
                    <div class="col-md-6">
                        <label for="tipus_usuari" class="form-label">
                            <?= __('register.user_type') ?>
                            <span class="text-danger" aria-label="<?= __('required') ?>">*</span>
                        </label>
                        <select id="tipus_usuari" 
                                name="tipus_usuari" 
                                class="form-select" 
                                required
                                aria-describedby="tipusHelp">
                            <option value="" selected disabled>
                                <?= __('register.select_option') ?>
                            </option>
                            <option value="tecnic"><?= __('register.type_tecnic') ?></option>
                            <option value="sala"><?= __('register.type_sala') ?></option>
                            <!-- Opcions desactivades temporalment -->
                            <!--<option value="productor"><?= __('register.type_productor') ?></option>-->
                            <!--<option value="banda"><?= __('register.type_banda') ?></option>-->
                        </select>
                        <div class="invalid-feedback">
                            <?= __('register.must_select') ?>
                        </div>
                    </div>

                    <!-- CONTRASENYA -->
                    <div class="col-md-6">
                        <label for="password" class="form-label">
                            <?= __('register.password') ?>
                            <span class="text-danger" aria-label="<?= __('required') ?>">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" 
                                   class="form-control" 
                                   id="password" 
                                   name="password"
                                   required 
                                   minlength="8"
                                   maxlength="128"
                                   pattern="(?=^.{8,}$)(?=.*[A-Za-z])(?=.*\d).*$"
                                   aria-describedby="passwordHelp"
                                   autocomplete="new-password">
                            <button class="btn btn-outline-secondary" 
                                    type="button" 
                                    id="togglePassword"
                                    aria-label="<?= __('register.toggle_password') ?>">
                                <i class="bi bi-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                        <div id="passwordHelp" class="form-text">
                            <?= __('register.password_help') ?>
                        </div>
                        <div class="invalid-feedback">
                            <?= __('register.invalid_password') ?>
                        </div>
                    </div>

                    <!-- CONFIRMAR CONTRASENYA -->
                    <div class="col-md-6">
                        <label for="password2" class="form-label">
                            <?= __('register.password_repeat') ?>
                            <span class="text-danger" aria-label="<?= __('required') ?>">*</span>
                        </label>
                        <input type="password" 
                               class="form-control" 
                               id="password2" 
                               name="password2"
                               required 
                               minlength="8" 
                               maxlength="128"
                               autocomplete="new-password"
                               aria-describedby="password2Help">
                        <div id="password2Help" class="form-text d-none text-success">
                            <i class="bi bi-check-circle-fill"></i> <?= __('register.passwords_match') ?>
                        </div>
                        <div class="invalid-feedback">
                            <?= __('register.password_mismatch') ?>
                        </div>
                    </div>

                    <!-- POLÍTICA DE PRIVACITAT -->
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" 
                                   type="checkbox" 
                                   role="switch" 
                                   value="1" 
                                   id="politica" 
                                   name="politica" 
                                   required
                                   aria-describedby="politicaHelp">
                            <label class="form-check-label" for="politica">
                                <?= __('register.accept_policy') ?> 
                                <a href="<?= h(BASE_PATH) ?>politica_privacitat.php" 
                                   target="_blank" 
                                   rel="noopener noreferrer">
                                    <?= __('register.policy') ?>
                                </a>.
                                <span class="text-danger" aria-label="<?= __('required') ?>">*</span>
                            </label>
                            <div class="invalid-feedback">
                                <?= __('register.must_accept_policy') ?>
                            </div>
                        </div>
                        <input type="hidden" name="politica_versio" value="2025-01">
                    </div>

                    <!-- BOTÓ DE SUBMIT -->
                    <div class="row justify-content-center mt-4">
                        <div class="col-md-4">
                            <button id="altaSubmitBtn" 
                                    class="btn btn-primary w-100" 
                                    type="submit">
                                <span id="altaSpinner" 
                                      class="spinner-border spinner-border-sm me-2 d-none" 
                                      role="status" 
                                      aria-hidden="true">
                                </span>
                                <span id="altaBtnText"
                                      data-default="<?= h(__('register.submit')) ?>"
                                      data-loading="<?= h(__('loading.processing')) ?>">
                                    <?= __('register.submit') ?>
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- NOTA LEGAL (opcional) -->
                    <div class="col-12 mt-3">
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i>
                            <?= __('register.legal_note') ?>
                        </small>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>

<!-- ============================================
     JAVASCRIPT: VALIDACIÓ I INTERACCIÓ
     ============================================ -->
<script>
/**
 * Sistema de validació unificat per al formulari de registre
 * - Validació en temps real de contrasenyes
 * - Toggle visibilitat contrasenya
 * - Prevenció de doble submit
 * - Feedback visual instantani
 */
(() => {
    'use strict';

    // Espera que el DOM estigui carregat
    document.addEventListener('DOMContentLoaded', () => {
        
        // ══════════════════════════════════════
        // ELEMENTS DEL FORMULARI
        // ══════════════════════════════════════
        const form = document.getElementById('registreForm');
        if (!form) return; // Sortir si no existeix el formulari

        const pass1 = document.getElementById('password');
        const pass2 = document.getElementById('password2');
        const pass2Help = document.getElementById('password2Help');
        const submitBtn = document.getElementById('altaSubmitBtn');
        const spinner = document.getElementById('altaSpinner');
        const btnText = document.getElementById('altaBtnText');
        const toggleBtn = document.getElementById('togglePassword');
        const toggleIcon = document.getElementById('toggleIcon');

        let submitting = false; // Flag per evitar doble submit

        // ══════════════════════════════════════
        // VALIDACIÓ DE CONTRASENYES
        // ══════════════════════════════════════
        const validatePasswords = () => {
            if (!pass1 || !pass2) return;

            if (pass1.value === '' || pass2.value === '') {
                // Camps buits: reseteja validació
                pass2.setCustomValidity('');
                if (pass2Help) pass2Help.classList.add('d-none');
                return;
            }

            if (pass1.value !== pass2.value) {
                // No coincideixen
                pass2.setCustomValidity('<?= h(__("register.password_mismatch")) ?>');
                if (pass2Help) pass2Help.classList.add('d-none');
            } else {
                // Coincideixen ✓
                pass2.setCustomValidity('');
                if (pass2Help) pass2Help.classList.remove('d-none');
            }
        };

        // ══════════════════════════════════════
        // TOGGLE VISIBILITAT CONTRASENYA
        // ══════════════════════════════════════
        if (toggleBtn && pass1 && toggleIcon) {
            toggleBtn.addEventListener('click', () => {
                const isPassword = pass1.type === 'password';
                pass1.type = isPassword ? 'text' : 'password';
                toggleIcon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
                toggleBtn.setAttribute('aria-label', 
                    isPassword ? '<?= h(__("register.hide_password")) ?>' : '<?= h(__("register.show_password")) ?>'
                );
            });
        }

        // ══════════════════════════════════════
        // VALIDACIÓ EN TEMPS REAL
        // ══════════════════════════════════════
        if (pass1 && pass2) {
            pass1.addEventListener('input', validatePasswords);
            pass2.addEventListener('input', validatePasswords);
        }

        // ══════════════════════════════════════
        // SUBMIT DEL FORMULARI
        // ══════════════════════════════════════
        form.addEventListener('submit', (event) => {
            
            // 1️⃣ Prevenir doble submit
            if (submitting) {
                event.preventDefault();
                event.stopPropagation();
                return;
            }

            // 2️⃣ Validar contrasenyes
            validatePasswords();

            // 3️⃣ Validació Bootstrap HTML5
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
                form.classList.add('was-validated');
                
                // Focus al primer camp invàlid
                const firstInvalid = form.querySelector(':invalid');
                if (firstInvalid) {
                    firstInvalid.focus();
                }
                return;
            }

            // 4️⃣ Activar estat "enviant"
            submitting = true;
            
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.classList.add('pe-none'); // Prevenir clics
            }
            
            if (spinner) {
                spinner.classList.remove('d-none');
            }
            
            if (btnText) {
                btnText.textContent = btnText.dataset.loading || 'Processing…';
            }

            // 5️⃣ Feedback visual als inputs (opcional)
            const inputs = form.querySelectorAll('input:not([type="hidden"]), select');
            inputs.forEach(input => {
                input.classList.add('pe-none'); // Desactiva interacció sense perdre valors
            });

            // 6️⃣ Fallback anti-bloqueig (15 segons)
            // Si el navegador no redirigeix, restaura l'estat
            setTimeout(() => {
                if (!document.hidden && submitting) {
                    submitting = false;
                    
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.classList.remove('pe-none');
                    }
                    
                    if (spinner) {
                        spinner.classList.add('d-none');
                    }
                    
                    if (btnText) {
                        btnText.textContent = btnText.dataset.default || '<?= h(__("register.submit")) ?>';
                    }

                    inputs.forEach(input => {
                        input.classList.remove('pe-none');
                    });

                    // Mostra un avís
                    console.warn('[alta.php] Submit timeout - restaurat estat del formulari');
                }
            }, 15000);
        });

        // ══════════════════════════════════════
        // NETEJA HONEYPOT (assegura que està buit)
        // ══════════════════════════════════════
        const honeypot = document.getElementById('website');
        if (honeypot) {
            honeypot.value = '';
        }

    }); // Fi DOMContentLoaded

})();
</script>

<style>
/* Millores visuals per al formulari */
.form-control:focus,
.form-select:focus {
    border-color: var(--bs-primary);
    box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.25);
}

.invalid-feedback {
    display: none;
}

.was-validated .form-control:invalid ~ .invalid-feedback,
.was-validated .form-select:invalid ~ .invalid-feedback,
.was-validated .form-check-input:invalid ~ .invalid-feedback {
    display: block;
}

/* Spinner personalitzat */
#altaSpinner {
    width: 1rem;
    height: 1rem;
}

/* Accessibilitat: focus visible */
.form-check-input:focus {
    box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.25);
}

/* Desactivar interacció visual */
.pe-none {
    pointer-events: none;
    opacity: 0.65;
}
</style>