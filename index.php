<?php
// index.php
declare(strict_types=1);
require_once __DIR__ . '/php/preload.php';
require_once __DIR__ . '/php/middleware.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ---------------------------------------------------------
 * Helper d'escapat (per si no ve d'altres includes)
 * --------------------------------------------------------- */
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ---------------------------------------------------------
 * B) Llegeix i prepara "login_modal" (sessió o cookie)
 *    - S'ha de fer ABANS de cap output
 * --------------------------------------------------------- */
$loginModal = $_SESSION['login_modal'] ?? null;

// Fallback via cookie
if (empty($loginModal) && !empty($_COOKIE['login_modal'])) {
  $tmp = json_decode($_COOKIE['login_modal'], true);
  if (is_array($tmp) && !empty($tmp['open'])) {
    $loginModal = $tmp;
  }
}

// Consumeix els senyals (un sol cop)
unset($_SESSION['login_modal']);
if (function_exists('ks_clear_login_modal_cookie')) {
  ks_clear_login_modal_cookie();
}

/* ---------------------------------------------------------
 * C) CSRF “lazy”: crea’l si no existeix
 * --------------------------------------------------------- */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* ---------------------------------------------------------
 * D) Resta d’includes (ja podem fer output)
 * --------------------------------------------------------- */
require_once __DIR__ . '/php/messages.php';
require_once __DIR__ . '/php/i18n.php';
require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';

/* ---------------------------------------------------------
 * E) Normalitza querystring per a banners/modals
 * --------------------------------------------------------- */
$qs_modal   = isset($_GET['modal'])   ? preg_replace('/[^a-z0-9_-]/i', '', (string)$_GET['modal'])   : '';
$qs_success = isset($_GET['success']) ? preg_replace('/[^a-z0-9_-]/i', '', (string)$_GET['success']) : '';
$qs_error   = isset($_GET['error'])   ? preg_replace('/[^a-z0-9_-]/i', '', (string)$_GET['error'])   : '';

/* ---------------------------------------------------------
 * F) Flags d'obertura de modals
 * --------------------------------------------------------- */
$shouldOpenRegister = ($qs_modal === 'register');
$shouldOpenLogin = false;
if (!$shouldOpenLogin && !empty($loginModal['open']) && empty($_SESSION['loggedin'])) {
  $shouldOpenLogin = true;
}
if (!$shouldOpenLogin && $qs_modal === 'login' && empty($_SESSION['loggedin'])) {
  $shouldOpenLogin = true;
}
?>

<!-- Obre modal de registre des de visualitza -->
<?php if ($shouldOpenRegister): ?>
<script>
window.addEventListener('load', function(){
  const el = document.getElementById('registre_usuaris');
  if (!el || !window.bootstrap) return;
  const modal = new bootstrap.Modal(el);
  modal.show();
});
</script>
<?php endif; ?>

<!-- Avís de versió 
<div class="text-center container my-5">
  <span class="text-body-tertiary font-monospace mb-0" style="font-size: .75rem;">
    <?= __('index.construccio') ?> <strong>v<?= h($GLOBALS['versio_web'] ?? '') ?></strong>
  </span>
</div>
-->

<!-- ALERTA global (fora de modals) per a success/error "generals"
     - Només si NO estem obrint el modal de login -->
<?php if (!empty($qs_success) && isset($messages['success'][$qs_success]) && $qs_modal !== 'login' && empty($loginModal)): ?>
  <div class="container mt-3" style="max-width: 720px;">
    <div class="alert alert-success alert-dismissible fade show shadow-sm mb-0" role="alert">
      <?= h((string)$messages['success'][$qs_success]) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= h(t('close')) ?>"></button>
    </div>
  </div>
<?php elseif (!empty($qs_error) && isset($messages['error'][$qs_error]) && $qs_modal !== 'login' && empty($loginModal)): ?>
  <div class="container mt-3" style="max-width: 720px;">
    <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-0" role="alert">
      <?= h((string)$messages['error'][$qs_error]) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= h(t('close')) ?>"></button>
    </div>
  </div>
<?php endif; ?>

<!-- HERO -->
<section class="container-fluid text-center" aria-labelledby="heroTitle">
  <img
    class="hero-logo img-fluid mx-auto d-block"
    src="<?= h(BASE_PATH) ?>img/kinosonik_riders.svg"
    alt="Kinosonik Riders — logotip"
    loading="lazy"
  />
  <!-- titol modal + missatge -->
  <h1 id="heroTitle" class="display-5 fw-bold text-body-emphasis mt-2 text-gradient"><?= __('index.hero.titol') ?></h1>
  <div class="col-lg-8 mx-auto px-3">
    <p class="lead mb-4"><?= __('index.hero.p1') ?></p>
    <div class="d-grid gap-2 d-sm-flex justify-content-sm-center">
      <button type="button" class="btn btn-primary btn-lg px-4" data-bs-toggle="modal" data-bs-target="#registre_usuaris">
        <?= __('index.comenca') ?>
      </button>
    </div>
    <!-- Versió --><p class="small mt-3"><?= __('index.construccio') ?> v<?= h($GLOBALS['versio_web'] ?? '') ?></p>
  </div>
</section> 

<!-- Secció Kairo :: 3 BOXs descriptius-->
 <section class="container my-5">
  <div class="row text-center g-4 justify-content-center">
    <!-- Puja el rider -->
    <div class="col-md-3">
      <div class="card bg-secondary bg-opacity-10 h-100">
        <div class="card-body">
          <i class="bi bi-cloud-upload display-6 text-danger mb-3"></i>
          <h5 class="mt-3"><?= __('index.pujarider') ?></h5>
          <p class="text-secondary"><?= __('index.pujarider.p1') ?></p>
        </div>
      </div>
    </div>
    <!-- Anàlisis amb IA -->
    <div class="col-md-3">
      <div class="card bg-secondary bg-opacity-10 h-100">
        <div class="card-body">
          <i class="bi bi-robot display-6 text-warning mb-3"></i>
          <h5 class="mt-3"><?= __('index.analisiia') ?></h5>
          <p class="text-secondary"><?= __('index.analisiia.p1') ?></p>
        </div>
      </div>
    </div>
    <!-- Validació i segell -->
    <div class="col-md-3">
      <div class="card bg-secondary bg-opacity-10 h-100">
        <div class="card-body">
          <i class="bi bi-shield-check display-6 text-success mb-3"></i>
          <h5 class="mt-3"><?= __('index.validacio') ?></h5>
          <p class="text-secondary"><?= __('index.validacio.p1') ?></p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Banner informatiu -->
<section class="bg-secondary bg-opacity-10 py-5 text-center">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-12">
        <p class="lead text-light" style="font-weight:200; font-size:2.5em;">
          <?= __('index.banner') ?>
        </p>
      </div>
    </div>
  </div>
</section>


<!-- QUIN USUARI ETS -->
<section class="container my-5" id="QuinUsuari" aria-labelledby="quiTitle">
  <div class="mx-auto px-md-4" style="max-width: 1120px;">
    <header class="text-center mb-5">
      <h2 id="quiTitle" class="display-5 fw-semibold lh-sm mb-2 text-gradient"><?= __('index.quinusuari.titol') ?></h2>
      <p class="text-secondary mb-0"><?= __('index.quinusuari.p1') ?></p>
    </header>

    <div class="row g-4 g-lg-5 align-items-stretch">
      <!-- Tècnic de banda -->
      <div class="col-12 col-md-6">
        <article class="card h-100 bg-secondary bg-opacity-10 border">
          <div class="card-body p-lg-5 d-flex flex-column shadow">
            <div class="text-center mb-3" style="margin-top: -.25rem;">
              <h3 class="fw-bold mb-1 text-body-emphasis" style="font-size: 2.4em;"><?= __('index.quinusuari.tecnic.titol') ?></h3>
              <p class="text-center small fw-light text-warning"><?= __('index.quinusuari.tecnic.sub') ?></p>
            </div>
            <p class="mb-4 text-center text-body-secondary">
              <?= __('index.quinusuari.tecnic.p1') ?>
            </p>
            <ul class="list-unstyled mb-0 tipus-usuari flex-grow-1">
              <li class="d-flex align-items-start mb-2"><i class="bi bi-arrow-right text-primary me-2"></i><span><?= __('index.quinusuari.tecnic.li1') ?></span></li>
              <li class="d-flex align-items-start mb-2"><i class="bi bi-arrow-right text-primary me-2"></i><span><?= __('index.quinusuari.tecnic.li2') ?></span></li>
              <li class="d-flex align-items-start mb-2"><i class="bi bi-arrow-right text-primary me-2"></i><span><?= __('index.quinusuari.tecnic.li3') ?></span></li>
              <li class="d-flex align-items-start mb-2"><i class="bi bi-arrow-right text-primary me-2"></i><span><?= __('index.quinusuari.tecnic.li4') ?></span></li>
              <li class="d-flex align-items-start mb-2"><i class="bi bi-arrow-right text-primary me-2"></i><span><?= __('index.quinusuari.tecnic.li5') ?></span></li>
              <li class="d-flex align-items-start"><i class="bi bi-arrow-right text-primary me-2"></i><span><?= __('index.quinusuari.tecnic.li6') ?></span></li>
            </ul>
            <div class="mt-auto text-center pt-4">
              <button type="button" class="btn btn-primary px-4" data-bs-toggle="modal" data-bs-target="#registre_usuaris">
                <?= __('index.comenca') ?>
              </button> 
            </div>
            <div class="mt-auto text-center pt-4">
              <a href="#ks-js" class="link-primary text-decoration-none"><?= __('index.saber.mes') ?>&nbsp;<i class="bi bi-arrow-down"></i></a>
            </div>
          </div>
        </article>
      </div>

      <!-- Sala / Promotor -->
      <div class="col-12 col-md-6">
        <article class="card h-100 bg-secondary bg-opacity-10 border">
          <div class="card-body p-lg-5 d-flex flex-column shadow">
            <div class="text-center mb-3" style="margin-top: -.25rem;">
              <h3 class="fw-bold mb-1 text-body-emphasis" style="font-size: 2.4em;"><?= __('index.quinusuari.sala.titol') ?></h3>
              <p class="text-center small fw-light text-warning"><?= __('index.quinusuari.sala.sub') ?></p>
            </div>
            <p class="text-body-secondary mb-4 text-center">
              <?= __('index.quinusuari.sala.p1') ?>
            </p>
            <ul class="list-unstyled mb-0 tipus-usuari flex-grow-1">
              <li class="d-flex align-items-start mb-2"><i class="bi bi-arrow-right text-primary me-2"></i><span><?= __('index.quinusuari.sala.li1') ?></span></li>
              <li class="d-flex align-items-start mb-2"><i class="bi bi-arrow-right text-primary me-2"></i><span><?= __('index.quinusuari.sala.li2') ?></span></li>
              <li class="d-flex align-items-start mb-2"><i class="bi bi-arrow-right text-primary me-2"></i><span><?= __('index.quinusuari.sala.li3') ?></span></li>
              <li class="d-flex align-items-start mb-2"><i class="bi bi-arrow-right text-primary me-2"></i><span><?= __('index.quinusuari.sala.li4') ?></span></li>
              <li class="d-flex align-items-start mb-2"><i class="bi bi-arrow-right text-primary me-2"></i><span><?= __('index.quinusuari.sala.li5') ?></span></li>
              <li class="d-flex align-items-start"><i class="bi bi-arrow-right text-primary me-2"></i><span><?= __('index.quinusuari.sala.li6') ?></span></li>
            </ul>
            <div class="mt-auto text-center pt-4">
              <button type="button" class="btn btn-primary px-4" data-bs-toggle="modal" data-bs-target="#registre_usuaris">
                <?= __('index.alta.gratuita') ?>
              </button>
            </div>
            <div class="mt-auto text-center pt-4">
              <a href="#SalaPromotor" class="link-primary text-decoration-none"><?= __('index.saber.mes') ?>&nbsp;<i class="bi bi-arrow-down"></i></a>
            </div>
          </div>
        </article>
      </div>
      
      <!-- Productor Tècnic PRO -->
      <!-- /to_replace/index_produccio_1.php -->

    </div>
  </div>
</section>

<!-- SABER-NE MÉS TÈCNICS -->
<section id="ks-js" class="container-fluid py-6" style="background-color:#181a1c;">
  <div class="container">
    <!-- CAPÇALERA -->
    <div class="row align-items-end g-4 mb-5">
      <div class="col-lg-8">
        <h2 class="display-4 fw-bold mb-3 text-gradient"><?= __('index.saber.mes.titular') ?></h2>
        <p class="lead text-secondary mb-3"><?= __('index.saber.mes.sub') ?></p>
      </div>
    </div>

    <div class="row g-5">
      <!-- Columna esquerra -->
      <div class="col-lg-6">
        <div class="mb-4">
          <h3 class="h4 fw-semibold mb-2"><?= __('index.saber.mes.tite') ?></h3>
          <p class="text-secondary mb-3"><?= __('index.saber.mes.sube') ?></p>
        </div>

        <!-- Targeta demo amb codi -->
        <div class="card border-0 bg-transparent">
          <pre class="ks-code"><code>AI · Automatic report
Rider status: "rider_detected"
score: 78/100
auto_seal: false

Summary:
"Correct structure, but with technical shortcomings."

Observations:
- Missing technical contact and direct phone.
- Incomplete channel list (7–12 without microphone).
- No version or revision date.
- No Stage Plot attached.

Recommendation:
Add contacts, complete the Input List and incorporate a stage plan...</code></pre>
        </div>
      </div>

      <!-- Columna dreta -->
      <div class="col-lg-6 ks-tecnica">
        <h3 class="h4 fw-semibold mb-2"><?= __('index.saber.mes.titd') ?></h3>
        <p class="text-secondary mb-3"><?= __('index.saber.mes.subd') ?></p>
        <hr class="border-secondary">
        <div class="row row-cols-1 row-cols-sm-2 g-3">

          <div class="col d-block p-2 h-100">
            <div class="fw-semibold"><i class="bi bi-cloud-check text-primary"></i><?= __('index.saber.mes.d1') ?></div>
            <small class="text-secondary"><?= __('index.saber.mes.d2') ?></small>
          </div>

          <div class="col d-block p-2 h-100">
            <div class="fw-semibold"><i class="bi bi-person-check text-primary"></i><?= __('index.saber.mes.d3') ?></div>
            <small class="text-secondary"><?= __('index.saber.mes.d4') ?></small>
          </div>

          <div class="col d-block p-2 h-100">
            <div class="fw-semibold"><i class="bi bi-patch-check text-primary"></i><?= __('index.saber.mes.d5') ?></div>
            <small class="text-secondary"><?= __('index.saber.mes.d6') ?></small>
          </div>

          <div class="col d-block p-2 h-100">
            <div class="fw-semibold"><i class="bi bi-qr-code text-primary"></i><?= __('index.saber.mes.d7') ?></div>
            <small class="text-secondary"><?= __('index.saber.mes.d8') ?></small>
          </div>

          <div class="col d-block p-2 h-100">
            <div class="fw-semibold"><i class="bi bi-shield-fill-check text-success"></i><?= __('index.saber.mes.d9') ?></div>
            <small class="text-secondary"><?= __('index.saber.mes.d10') ?></small>
          </div>

          <div class="col d-block p-2 h-100">
            <div class="fw-semibold"><i class="bi bi-shield-fill-x text-danger"></i><?= __('index.saber.mes.d11') ?></div>
            <small class="text-secondary"><?= __('index.saber.mes.d12') ?></small>
          </div>

          <div class="col d-block p-2 h-100">
            <div class="fw-semibold"><i class="bi bi-question-circle text-primary"></i><?= __('index.saber.mes.d13') ?></div>
            <small class="text-secondary"><?= __('index.saber.mes.d14') ?></small>
          </div>

          <div class="col d-block p-2 h-100">
            <div class="fw-semibold"><i class="bi bi-send text-primary"></i><?= __('index.saber.mes.d15') ?></div>
            <small class="text-secondary"><?= __('index.saber.mes.d16') ?></small>
          </div>

        </div>
      </div>
    </div>
  </div>
</section>

<!-- FI -> SABER-NE MÉS TÈCNICS -->

<!-- PRODUCTOR TÈCNIC PRO — Saber-ne més -->
<!-- To replace a /to_replace/index_produccio_2.php -->

<!-- SALA / PROMOTOR — Títol, text i imatge -->

<section id="SalaPromotor" class="container-fluid bg-black text-white py-6 pb-0 mb-0" aria-labelledby="salaTitle">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-10 col-xl-9">

        <!-- Títol -->
        <h2 id="salaTitle" class="display-4 fw-bold lh-1 mb-3"><?= __('index.promotors.titol') ?></h2>

        <!-- Paràgraf introductori -->
        <p class="lead text-secondary mb-4"><?= __('index.promotors.descripcio') ?></p>
        
        <!-- Botó alta gratuita -->
         <div class="mt-auto text-center pt-4">
              <button type="button" class="btn btn-primary px-4" data-bs-toggle="modal" data-bs-target="#registre_usuaris">
                <?= __('index.alta.gratuita') ?>
              </button>
            </div>

        <!-- Imatge (mockup/captura) -->
        <img
          src="/img/section/mac.jpg"
          class="img-fluid mx-auto d-block img-edge"
          alt="Consulta de rider validat amb QR des d’una sala o promotor"
          loading="lazy"
        />

      </div>
    </div>
  </div>
</section>

<!-- FI -> SABER-NE MÉS SALA/PROMOTORS -->

<!-- MODAL Registre -->
<?php include __DIR__ . "/parts/alta.php"; ?>

<!-- MODAL Login -->
<div class="modal fade" id="login_usuaris" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="loginLabel" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content liquid-glass-kinosonik">
      <div class="modal-header border-0 text-white">
        <h1 class="modal-title fs-5" id="loginLabel"><?= h(t('login.title')) ?></h1>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button>
      </div>
      <div class="modal-body">
        <?php
        // Decideix una sola alerta per al modal (prioritza el flash de sessió/cookie)
        $modalAlert = null;
        if (!empty($loginModal['flash']['msg'])) {
          $t = $loginModal['flash']['type'] ?? 'info';
          $class = match ($t) {
            'success' => 'alert-success',
            'danger', 'error' => 'alert-danger',
            'warning' => 'alert-warning',
            'info'    => 'alert-info',
            default   => 'alert-secondary'
          };
          $modalAlert = ['class' => $class, 'msg' => (string)$loginModal['flash']['msg']];
        } elseif ($qs_modal === 'login') {
          if (!empty($qs_success) && isset($messages['success'][$qs_success])) {
            $modalAlert = ['class' => 'alert-success', 'msg' => (string)$messages['success'][$qs_success]];
          } elseif (!empty($qs_error) && isset($messages['error'][$qs_error])) {
            $modalAlert = ['class' => 'alert-danger', 'msg' => (string)$messages['error'][$qs_error]];
          }
        }
        ?>

<?php if ($modalAlert): ?>
  <div class="alert <?= h($modalAlert['class']) ?> small mb-3">
    <?= h($modalAlert['msg']) ?>
  </div>
<?php endif; ?>
        <?php if ($qs_modal === 'login' && ($qs_error === 'email_not_verified')): ?>
          <form class="mt-2" method="POST" action="<?= h(BASE_PATH) ?>php/resend_verification.php">
            <?= csrf_field() ?>
            <input type="hidden" name="return" value="<?= h($_GET['return'] ?? '') ?>">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
            <input type="hidden" name="email" value="<?= h($_GET['email'] ?? '') ?>">
            <button type="submit" class="btn btn-outline-primary btn-sm"><?= h(t('login.resend_verification')) ?></button>
          </form>
        <?php endif; ?>

        <!-- Alerta oculta per al flux "oblidat la contrasenya" -->
        <div id="forgotAlert" class="alert alert-warning d-none small" role="alert">
          <?= h(t('login.forgot.enter_email_alert')) ?>
        </div>

        <form class="row g-3 needs-validation" method="POST" action="<?= h(BASE_PATH) ?>php/login.php" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="csrf"   value="<?= h($_SESSION['csrf'] ?? '') ?>">
          <input type="hidden" name="return" value="<?= h($_GET['return'] ?? '') ?>">

          <div class="col-12">
            <label for="loginEmail" class="form-label"><?= h(t('login.email')) ?></label>
            <input type="email" class="form-control" id="loginEmail" name="email" required autocomplete="username">
            <div class="invalid-feedback"><?= h(t('validation.email_invalid')) ?></div>
          </div>

          <div class="col-12">
            <label for="loginPassword" class="form-label"><?= h(t('login.password')) ?></label>
            <input type="password" class="form-control" id="loginPassword" name="contrasenya" required minlength="8" autocomplete="current-password">
            <div class="invalid-feedback"><?= h(t('validation.password_required')) ?></div>
          </div>

          <div class="col-12">
            <button class="btn btn-primary w-100" type="submit" id="loginBtn"><?= h(t('login.submit')) ?></button>
          </div>

          <div class="col-12 text-center">
            <button type="button" id="forgotPasswordBtn" class="btn btn-link p-0 small text-decoration-none">
              <?= h(t('login.forgot.link')) ?>
              <span id="forgotSpinner" class="spinner-border spinner-border-sm ms-2 d-none" role="status" aria-hidden="true"></span>
            </button>
          </div>
        </form>
      </div>

    </div>
  </div>
</div>

<?php include __DIR__ . "/parts/footer.php"; ?>

<script>
/* Auto-dismiss de l’alert global, si n’hi ha */
document.addEventListener('DOMContentLoaded', () => {
  const alertEl = document.querySelector('.alert.alert-dismissible');
  if (alertEl) {
    setTimeout(() => {
      const inst = bootstrap.Alert.getOrCreateInstance(alertEl);
      inst.close();
    }, 4000);
  }
});
</script>

<script>
/* Flux “oblidat la contrasenya” al modal de login */
(() => {
  'use strict';
  const CSRF_TOKEN = "<?= h($_SESSION['csrf'] ?? '') ?>";
  const btn        = document.getElementById('forgotPasswordBtn');
  const spinner    = document.getElementById('forgotSpinner');
  const emailInput = document.getElementById('loginEmail');
  const passInput  = document.getElementById('loginPassword');
  const alertBox   = document.getElementById('forgotAlert');
  if (!btn) return;

  let sending = false;
  const enableUI = () => {
    sending = false;
    btn.disabled = false;
    spinner?.classList.add('d-none');
    if (emailInput) emailInput.readOnly = false;
    if (passInput)  passInput.readOnly  = false;
  };

  btn.addEventListener('click', (e) => {
    e.preventDefault();
    if (sending) return;

    const email = (emailInput?.value || '').trim();
    const valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);

    if (!valid) { alertBox?.classList.remove('d-none'); return; }
    alertBox?.classList.add('d-none');

    sending = true;
    btn.disabled = true;
    spinner?.classList.remove('d-none');
    if (emailInput) emailInput.readOnly = true;
    if (passInput)  passInput.readOnly  = true;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = "<?= h(BASE_PATH) ?>php/reset_request.php";

    const ie = document.createElement('input');
    ie.type = 'hidden'; ie.name = 'email'; ie.value = email;

    const ic = document.createElement('input');
    ic.type = 'hidden'; ic.name = 'csrf';  ic.value = CSRF_TOKEN;

    form.appendChild(ie);
    form.appendChild(ic);
    document.body.appendChild(form);

    const fallback = setTimeout(enableUI, 3000);
    form.submit();
  });
})();
</script>

<script>
/* Validació Bootstrap */
(() => {
  'use strict';
  const forms = document.querySelectorAll('.needs-validation');
  Array.from(forms).forEach(form => {
    form.addEventListener('submit', event => {
      if (!form.checkValidity()) { event.preventDefault(); event.stopPropagation(); }
      form.classList.add('was-validated');
    }, false);
  });
})();
</script>

<?php if ($shouldOpenLogin): ?>
<script>
/* Obrim el modal de login quan cal i NO estàs loguejat */
window.addEventListener('load', function(){
  const el = document.getElementById('login_usuaris');
  if (!el || !window.bootstrap) return;
  const modal = new bootstrap.Modal(el);
  modal.show();
});
</script>
<?php endif; ?>