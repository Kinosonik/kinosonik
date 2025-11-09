<?php
// dades.php
declare(strict_types=1);
require_once __DIR__ . '/php/preload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . "/php/db.php";
require_once __DIR__ . "/php/i18n.php";     // ← IMPORTANT: abans d'usar t()
require_once __DIR__ . "/php/messages.php"; // per als banners (si en fas servir)
require_once __DIR__ . '/php/middleware.php';
$pdo = db();

// Helper d'escapat
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* --------- Guard d’accés: cal estar loguejat --------- */
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
if ($sessionUserId <= 0 || empty($_SESSION['loggedin'])) {
  $ret = BASE_PATH . 'espai.php?seccio=dades';
  ks_redirect(BASE_PATH . 'index.php?modal=login&return=' . rawurlencode($ret), 302);
  exit;
}

/* --------- És admin? --------- */
$sessionIsAdmin = false;
try {
  $stAdmin = $pdo->prepare("SELECT Tipus_Usuari FROM Usuaris WHERE ID_Usuari = ? LIMIT 1");
  $stAdmin->execute([$sessionUserId]);
  $r = $stAdmin->fetch(PDO::FETCH_ASSOC);
  $sessionIsAdmin = $r && strcasecmp((string)$r['Tipus_Usuari'], 'admin') === 0;
} catch (Throwable $e) {
  ks_redirect(BASE_PATH . 'index.php?modal=login&return=' . rawurlencode($ret), 302);
  exit;
}

/* --------- Target: self o, si admin, ?user=ID --------- */
$targetUserId = $sessionUserId;
if ($sessionIsAdmin && isset($_GET['user']) && ctype_digit((string)$_GET['user'])) {
  $targetUserId = (int)$_GET['user'];
}

/* --------- GET dades usuari --------- */
$stmt = $pdo->prepare("
  SELECT ID_Usuari, Nom_Usuari, Cognoms_Usuari, Telefon_Usuari, Email_Usuari,
         Tipus_Usuari, Data_Alta_Usuari, COALESCE(Idioma,'ca') AS Idioma,
         COALESCE(Publica_Telefon, 1) AS Publica_Telefon
    FROM Usuaris
   WHERE ID_Usuari = ?
   LIMIT 1
");
$stmt->execute([$targetUserId]);
$usuari = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$usuari) {
  header("Location: " . BASE_PATH . "index.php?error=db_error", true, 302);
  exit;
}

/* --------- Rol tècnic (estat del TARGET) + permís de toggle --------- */
$targetRole = strtolower((string)($usuari['Tipus_Usuari'] ?? ''));
$canToggleTechRole = ($sessionIsAdmin || $targetRole === 'productor'); // només productors (o admin)

$stRoleTech = $pdo->prepare("SELECT 1 FROM User_Roles WHERE user_id = ? AND role = 'tecnic' LIMIT 1");
$stRoleTech->execute([$targetUserId]);
$targetHasTech = (bool)$stRoleTech->fetchColumn();

/* --------- Banner / Flash --------- */
$banner = null;

// Flash d'èxit (posat per save_profile.php)
if (!empty($_SESSION['flash_success'])) {
  $key = (string)$_SESSION['flash_success'];
  unset($_SESSION['flash_success']);
  if (!empty($messages['success'][$key])) {
    $banner = ['type' => 'success', 'text' => $messages['success'][$key]];
  } else {
    $banner = ['type' => 'success', 'text' => t('profile.updated_ok') ?? ($messages['success']['updated'] ?? 'OK')];
  }
}

$tipus = (string)($usuari['Tipus_Usuari'] ?? '');
$colors = [
  "tecnic" => "bg-primary",
  "sala"   => "bg-success",
  "productor"  => "bg-warning text-light",
  "admin"  => "bg-danger text-white fw-bold"
];
$classe = $colors[$tipus] ?? "bg-secondary";

// Per al selector d’idioma
$langCurrent = current_lang(); // definit a i18n.php

// Paraula de confirmació d'eliminació (multillengua)
$confirmWords = ['ca' => 'ELIMINAR', 'es' => 'ELIMINAR', 'en' => 'DELETE'];
$confirmWord  = $confirmWords[$langCurrent] ?? 'ELIMINAR';
$confirmPattern = '^' . preg_quote($confirmWord, '/') . '$';
?>
<div class="container my-5" style="max-width: 720px;">
  <div class="card shadow-sm">
    <div class="card-body fw-lighter">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h4 class="mb-0"><?= h(t('profile.title')) ?></h4>
        <span class="badge <?= h($classe) ?> fw-lighter px-3 py-2 text-uppercase bg-kinosonik">
          <?php
            $roleLabel = match (strtolower($tipus)) {
              'tecnic' => t('profile.role.tech'),
              'sala'   => t('profile.role.venue'),
              'productor'  => t('profile.role.productor'),
              'admin'  => t('profile.role.admin'),
              default  => strtoupper($tipus)
            };
            echo h((string)$roleLabel);
          ?>
        </span>
      </div>
      <small class="text-secondary d-block mb-2">
        ID: <?= h((string)($usuari['ID_Usuari'] ?? '')) ?> /
        <?= h(t('profile.id_and_signup_date')) ?>:
        <?php
        $dataOriginal = $usuari['Data_Alta_Usuari'] ?? null;
        try {
          echo $dataOriginal ? h((new DateTime($dataOriginal))->format('d/m/Y')) : '—';
        } catch (Throwable $e) { echo '—'; }
        ?>
      </small>
      <?php if ($banner): ?>
        <div class="alert alert-<?= $banner['type']==='success' ? 'success' : 'danger' ?>"><?= h((string)$banner['text']) ?></div>
      <?php endif; ?>
      <hr>
      <?php
      $targetRole = strtolower((string)($usuari['Tipus_Usuari'] ?? ''));
      $canTogglePublishPhone = ($targetRole !== 'sala') || $sessionIsAdmin;
      // Si NO es pot mostrar, afegeix un hidden per conservar el valor actual sense ensenyar el switch
      ?>
      <!-- Formulari principal (perfil + idioma) -->
      <form class="needs-validation mt-3" method="post" action="<?= h(BASE_PATH) ?>php/save_profile.php" novalidate>
        <?= csrf_field() ?>
        <?php if ($sessionIsAdmin && $targetUserId !== $sessionUserId): ?>
        <input type="hidden" name="user_id" value="<?= (int)$targetUserId ?>">
        <?php endif; ?>
        <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? (BASE_PATH.'espai.php?seccio=dades')) ?>">

        <!-- Nom i cognoms -->
        <div class="row mb-3">
          <div class="col-md-4">
            <label for="nom" class="form-label"><?= h(t('profile.name')) ?></label>
            <input type="text" class="form-control" id="nom" name="nom"
                   value="<?= h($usuari['Nom_Usuari'] ?? '') ?>"
                   pattern="^[A-Za-zÀ-ÿ' -]{2,}$" required>
            <div class="invalid-feedback"><?= h(t('validation.name_invalid')) ?></div>
          </div>
          <div class="col-md-8">
            <label for="cognoms" class="form-label"><?= h(t('profile.surnames')) ?></label>
            <input type="text" class="form-control" id="cognoms" name="cognoms"
                   value="<?= h($usuari['Cognoms_Usuari'] ?? '') ?>"
                   pattern="^[A-Za-zÀ-ÿ' -]{2,}$" required>
            <div class="invalid-feedback"><?= h(t('validation.surnames_invalid')) ?></div>
          </div>
        </div>

        <!-- Telèfon + Email -->
        <div class="row mb-3">
          <div class="col-md-4">
            <label for="telefon" class="form-label"><?= h(t('profile.phone')) ?></label>
            <input type="tel" class="form-control" id="telefon" name="telefon"
                   value="<?= h($usuari['Telefon_Usuari'] ?? '') ?>"
                   pattern="^\+[1-9][0-9]{6,14}$" required>
            <div class="form-text"><?= h(t('profile.phone_format_hint')) ?></div>
            <div class="invalid-feedback"><?= h(t('validation.phone_invalid')) ?></div>
          </div>
          <div class="col-md-8">
            <label for="email" class="form-label"><?= h(t('profile.email')) ?></label>
            <input type="email" class="form-control" id="email" name="email"
                   value="<?= h($usuari['Email_Usuari'] ?? '') ?>" required>
            <div class="invalid-feedback"><?= h(t('validation.email_invalid')) ?></div>
          </div>
        </div>
        
        <?php if ($canTogglePublishPhone): ?>
        <!-- Publicar telèfon (switch) -->
        <div class="row mb-3">
          <div class="col-md-12 d-flex align-items-end">
            <input type="hidden" name="publica_telefon" value="0">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch"
                id="publica_telefon" name="publica_telefon" value="1"
                <?= ((int)($usuari['Publica_Telefon'] ?? 1) === 1) ? 'checked' : '' ?>>
              <label class="form-check-label" for="publica_telefon">
                <?= h(t('profile.publish_phone.label')) ?>
              </label>
              <div class="form-text"><?= h(t('profile.publish_phone.help')) ?></div>
            </div>
          </div>
        </div>
      <?php else: ?>
        <!-- Ocultem el control per a 'sala' (excepte si l’admin edita) -->
        <input type="hidden" name="publica_telefon" value="<?= (int)($usuari['Publica_Telefon'] ?? 0) ?>">
      <?php endif; ?>

        <!-- Nova contrasenya opcional -->
        <div class="row mb-3">
          <div class="col-md-6">
            <label for="password" class="form-label"><?= h(t('profile.new_password_opt')) ?></label>
            <div class="input-group">
              <input type="password" class="form-control" id="password" name="password"
                     pattern="^(?=.*[A-Za-z])(?=.*\d).{8,}$" autocomplete="new-password">
              <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                <i class="bi bi-eye"></i>
              </button>
              <div class="invalid-feedback"><?= h(t('validation.pwd_strength')) ?></div>
            </div>
          </div>

          <div class="col-md-6">
            <label for="confirmPassword" class="form-label"><?= h(t('profile.confirm_password')) ?></label>
            <div class="input-group">
              <input type="password" class="form-control" id="confirmPassword" name="confirmPassword" autocomplete="new-password">
              <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                <i class="bi bi-eye"></i>
              </button>
              <div class="invalid-feedback"><?= h(t('validation.pwd_match')) ?></div>
            </div>
          </div>
        </div>

        <!-- Idioma -->
        <div class="row mb-3">
          <div class="col-md-4">
            <label class="form-label small"><?= h(t('settings.language')) ?></label>
            <div style="max-width: 320px;">
              <select name="idioma" class="form-select form-select-sm">
                <option value="ca" <?= $langCurrent==='ca'?'selected':'' ?>><?= h(t('lang.ca')) ?></option>
                <option value="es" <?= $langCurrent==='es'?'selected':'' ?>><?= h(t('lang.es')) ?></option>
                <option value="en" <?= $langCurrent==='en'?'selected':'' ?>><?= h(t('lang.en')) ?></option>
              </select>
            </div>
          </div>
        </div>
        <div class="d-flex justify-content-center gap-2">
          <button class="btn btn-primary">
            <i class="bi-person-gear"></i>
            <?= h(t('btn.update')) ?>
          </button>
        </div>
      </form>
      <?php if ($canToggleTechRole): ?>


  <!-- Rol addicional: Tècnic (només productors o admin) -->
<?php $potVeureSwitchTecnic = (ks_is_admin() || ks_is_productor()); ?>
<?php if ($potVeureSwitchTecnic): ?>
  <hr class="my-4">
  <div class="row mb-3">
    <div class="col-md-12">
      <label class="form-label d-block mb-2"><strong><?= h(t('profile.role.section_title') ?? 'Activar/Desactivar el rol de tècnic') ?></strong></label>
      <form id="formRoleTecnic" method="post" action="<?= h(BASE_PATH) ?>php/profile_roles.php" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? (BASE_PATH.'espai.php?seccio=dades')) ?>">
        <input type="hidden" name="role_tecnic" id="role_tecnic_value" value="<?= ks_is_tecnic() ? '1':'0' ?>">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="role_tecnic_sw" <?= ks_is_tecnic()?'checked':'' ?>>
          <label class="form-check-label" for="role_tecnic_sw">
            <?= h(t('profile.role.toggle_tecnic_label') ?? 'Permet pujar i gestionar riders com a tècnic, a més del teu rol de productor. Compartiràs espai de disc dur.') ?>
          </label>
          <div class="form-text">
            <?= h(t('profile.role.toggle_tecnic_help') ?? 'En desactivar-lo s’eliminaran tots els teus riders, els seus logs i fitxers del núvol.') ?>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal confirmació baixa rol tècnic -->
  <div class="modal fade" id="modalDropTech" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content  liquid-glass-kinosonik needs-validation" method="POST" action="<?= h(BASE_PATH) ?>php/profile_roles.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? (BASE_PATH.'espai.php?seccio=dades')) ?>">
        <input type="hidden" name="role_tecnic" value="0">
        <input type="hidden" name="wipe_riders" value="1">

        <div class="modal-header bg-kinosonik">
          <h5 class="modal-title"><?= h(t('profile.role.drop_tech_title') ?? 'Eliminar el rol de tècnic') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('btn.cancel')) ?>"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">
            <?= h(t('profile.role.drop_tech_desc') ??
            'Si confirmes, s’eliminaran tots els teus riders, execucions d’IA, logs i fitxers associats a aquests riders. Aquesta acció és irreversible.') ?>
          </p>
          <?php
            $confirmWords = ['ca' => 'ELIMINAR', 'es' => 'ELIMINAR', 'en' => 'DELETE'];
            $langCurrent = current_lang();
            $confirmWord  = $confirmWords[$langCurrent] ?? 'ELIMINAR';
            $confirmPattern = '^' . preg_quote($confirmWord, '/') . '$';
          ?>
          <label class="form-label">
            <?= h(sprintf(t('profile.delete_type_to_confirm') ?? 'Escriu %s per confirmar:', $confirmWord)) ?>
          </label>
          <input type="text" class="form-control" name="confirm" required pattern="<?= h($confirmPattern) ?>">
          <div class="invalid-feedback"><?= h($confirmWord) ?></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= h(t('btn.cancel')) ?></button>
          <button type="submit" class="btn btn-danger"><?= h(t('btn.confirm')) ?></button>
        </div>
      </form>
    </div>
  </div>

  <script>
    (() => {
      const sw = document.getElementById('role_tecnic_sw');
      const form = document.getElementById('formRoleTecnic');
      const val  = document.getElementById('role_tecnic_value');
      if (!sw || !form || !val) return;

      sw.addEventListener('change', (ev) => {
        const checked = sw.checked === true;
        val.value = checked ? '1' : '0';
        if (checked) {
          // Activar rol → directe
          form.submit();
        } else {
          // Desactivar rol → modal confirmació
          const m = new bootstrap.Modal(document.getElementById('modalDropTech'));
          m.show();
          // Reverteix visualment el switch fins que confirmi al modal
          sw.checked = true;
        }
      });
    })();
  </script>
  <?php endif; ?>
<?php endif; ?>

      <hr class="my-4">

      <!-- Eliminar compte -->
      <div class="d-flex justify-content-center align-items-center">
          <span class="text-danger small"><?= h(t('profile.delete_irreversible')) ?></span>
        </div>
        <div class="d-flex justify-content-center align-items-center mt-3">
          <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalDeleteAccount">
            <i class="bi bi-x-lg me-1"></i>
            <?= h(t('profile.delete_open')) ?>
          </button>
      </div>

<!-- Modal confirmació eliminació -->
<div class="modal fade" id="modalDeleteAccount" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content liquid-glass-kinosonik needs-validation" method="POST" action="<?= h(BASE_PATH) ?>php/delete_account.php" novalidate>
    <?= csrf_field() ?>
      <div class="modal-header bg-danger border-0">
        <h5 class="modal-title"><?= h(t('profile.delete_title')) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('btn.cancel')) ?>"></button>
      </div>
      <div class="modal-body">
        <p><?= h(t('profile.delete_desc')) ?></p>
        <div class="mb-3">
          <label class="form-label">
            <!-- Si t('profile.delete_type_to_confirm') no accepta placeholders, mostra-ho així: -->
            <?= h(sprintf(t('profile.delete_type_to_confirm'), $confirmWord)) ?>
          </label>
          <input type="text"
                 class="form-control"
                 name="confirm"
                 required
                 pattern="<?= h($confirmPattern) ?>">
          <div class="invalid-feedback"><?= h($confirmWord) ?></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= h(t('btn.cancel')) ?></button>
        <button type="submit" class="btn btn-danger"><?= h(t('profile.delete_confirm')) ?></button>
      </div>
    </form>
  </div>
</div>

    </div>
  </div>
</div>

<script>
// Mostrar / ocultar contrasenya
function togglePasswordVisibility(inputId, buttonId) {
  const input = document.getElementById(inputId);
  const button = document.getElementById(buttonId);
  if (!input || !button) return;
  const icon = button.querySelector('i');
  button.addEventListener('click', () => {
    if (input.type === 'password') {
      input.type = 'text';
      icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
      input.type = 'password';
      icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
  });
}
togglePasswordVisibility('password', 'togglePassword');
togglePasswordVisibility('confirmPassword', 'toggleConfirmPassword');

// Validació Bootstrap + match contrasenya
(() => {
  'use strict';
  const forms = document.querySelectorAll('.needs-validation');
  Array.from(forms).forEach(form => {
    form.addEventListener('submit', event => {
      const p1 = document.getElementById('password');
      const p2 = document.getElementById('confirmPassword');
      if (p1 && p2) {
        p2.setCustomValidity('');
        if (p1.value !== '' || p2.value !== '') {
          if (p1.value !== p2.value) {
            p2.setCustomValidity('<?= h(t('validation.pwd_match')) ?>');
          }
        }
      }
      if (!form.checkValidity()) { event.preventDefault(); event.stopPropagation(); }
      form.classList.add('was-validated');
    }, false);
  });
})();
</script>