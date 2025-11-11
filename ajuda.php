<?php
declare(strict_types=1);

// ── Sessió mínima (només si vols mantenir idioma o login) ────────────────
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ── Diccionari d'idioma bàsic ────────────────────────────────────────────
$lang = $_SESSION['lang'] ?? 'ca';
$lang = preg_replace('/[^a-z]/i', '', $lang) ?: 'ca';
$langFile = __DIR__ . "/lang/{$lang}.php";
$t = (is_file($langFile) ? require $langFile : []);
if (!is_array($t)) $t = [];
$T = fn(string $k, string $fallback='') => $t[$k] ?? ($fallback !== '' ? $fallback : $k);

// ── Helper HTML escapador ───────────────────────────────────────────────
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// -- Helper tipus usuari
$role = $_SESSION['tipus_usuari'] ?? 'visitant';

function ks_can_view(array $roles): bool {
  global $role;
  return in_array($role, $roles, true);
}

// ── Capçalera i navbar (reutilitzem el disseny global) ──────────────────
require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';
?>

<style>
/* ── Sidebar sticky + glass subtil ───────────────────────── */
aside.docs-aside{
  position: sticky;
  top: var(--nav-offset);
  z-index: 1020;
}
.docs-nav{
  position: sticky;
  top: var(--nav-offset);
  max-width: 220px;
  font-size: .875rem;
}
.docs-nav .card-body{ padding: .5rem .5rem; }

/* ── Títols de grup + icona exactament alineada ─────────── */
.docs-nav .nav-group{ position: relative; margin-bottom: .35rem; padding-bottom: .35rem; }
.docs-nav .nav-group + .nav-group{ padding-top: .35rem; }

.docs-nav .nav-title{
  position: relative;
  display: block;
  padding-left: 1.9rem;         /* reserva per a la icona */
  color: #e8e8e8;
  font-weight: 600;
  line-height: 1.2;
}
.docs-nav .nav-title i{
  position: absolute;
  left: .35rem;
  top: -0.05rem;                   /* cau amb la x-height del text */
  width: 1.2rem; height: 1.2rem;
  display: grid; place-items: center;
  font-size: .85rem;
  color: #cfcfcf;
}

/* ── Sublinks: alineats amb el text del títol ───────────── */
.docs-nav .nav-sub{ margin-top: .2rem; }
.docs-nav .nav-sub .nav-link{
  display: block;
  padding: .2rem .25rem .2rem 1.9rem;
  color: var(--ks-text);
  border-left: 2px solid transparent;
  border-radius: 0;
  text-decoration: none;
}
.docs-nav .nav-sub .nav-link:hover{ color: var(--ks-text-strong); background: rgba(255,255,255,.04); }
.docs-nav .nav-sub .nav-link.active{
  color: var(--ks-text-strong);
  border-left-color: var(--ks-border);
  background: rgba(255,255,255,.04);
}

/* ── Cos central i tipografia ───────────────────────────── */
.docs-section{ padding-top: .75rem; margin-bottom: 2rem; }
.docs-section > .h4{
  margin-bottom: .75rem;
  padding-bottom: .35rem;
  padding-top: 0.3em;
  color: #fff;
  font-size: 2rem;
  font-weight: 700;
  border-top: 1px solid #3b3b3b;
}
.docs-section > .h5{
  margin: 3.5rem 0 .6rem;
  font-size: 1.6rem;
  color: #eaeaea;
  font-weight: 600;
}
.docs-section p{ font-size: 1rem; color: var(--ks-text); font-weight: 300; }
.docs-section a{ text-decoration: none; }
.docs-section code{ color: #c64282; }

/* ── Ancoratge net sota navbar quan cliques al menú ─────── */
.docs-section [id]{ scroll-margin-top: calc(var(--nav-offset) + 10px); }

/* ── Taules de docs ─────────────────────────────────────── */
.table.docs-table{
  width: 100%;
  margin: 0 auto 1rem;
  font-size: .9rem;
  color: var(--ks-text);
}
.table.docs-table thead th{
  color: var(--ks-text);
  border-bottom-color: var(--ks-border);
}

.table.docs-table tbody tr + tr td,
.table.docs-table tbody tr + tr th{
  border-top: 1px solid var(--ks-border);
  color: #fff;
  font-weight: 200;
  line-height: 1.8em;
}
.table.docs-table th{ width: 1%; white-space: nowrap; vertical-align: top; color: var(--ks-text);}

.table.docs-table tbody > tr:first-child > th {
  background-color: transparent;
  color: #fff;
  font-weight: 200;
}
.table.docs-table tbody > tr:first-child {
  background-color: transparent;
  color: var(--ks-text) !important;
  font-weight: 200;
}

/* Amplada més “editorial” en pantalles grans */
@media (min-width: 992px){
  .table.docs-table{ width: 80%; }
}

/* ── Mòbil: sidebar sense glass i 100% ──────────────────── */
@media (max-width: 991.98px){
  .docs-nav{
    top: 64px;
    background: transparent;
    border: 0;
    -webkit-backdrop-filter: none;
    backdrop-filter: none;
  }
}
</style>
<?php if (ks_can_view(['admin','tecnic','banda','productor'])): ?><?php endif;?>


  <div class="container">
    <div class="row g-4">
      <!-- Sidebar -->
      <aside class="col-12 col-lg-2 docs-aside">
        <nav id="docsNav" class="docs-nav card border-0">
          <div class="card-body px-2 py-3">
             <!-- Ajuda: Dades personal -->
            <div class="nav-group">
                <span class="nav-title">
                    <i class="bi bi-person-circle"></i>
                    <?= __('nav.your_data') ?>
                </span>
                <nav class="nav-sub">
                    <a class="nav-link" href="#id_dadespersonals"><?= __('ajuda.elteuid') ?></a>
                    <a class="nav-link" href="#canvi_contrasenya"><?= __('ajuda.contrasenya') ?></a>
                    <a class="nav-link" href="#publicar_telefon"><?= __('ajuda.publicartelefon') ?></a>
                    <a class="nav-link" href="#rol_productor"><?= __('ajuda.productor') ?>
                    <a class="nav-link" href="#canvi_idioma"><?= __('ajuda.idioma') ?></a>
                </nav>
            </div>

            <!-- Ajuda: Els teus riders -->
            <div class="nav-group">
                <span class="nav-title">
                    <i class="bi bi-archive me-1"></i>
                    <?= __('nav.your_riders') ?>
                </span>
                <nav class="nav-sub">
                    <a class="nav-link" href="#pujar_rider"><?= __('ajuda.pujarrider') ?></a>
                    <a class="nav-link" href="#taula_rider"><?= __('ajuda.taularider') ?></a>
                    <a class="nav-link" href="#accions_rider"><?= __('ajuda.accionsrider') ?></a>
                    <a class="nav-link" href="#detalls_ia"><?= __('ajuda.detallsia') ?></a>                    
                </nav>
            </div>

            <!-- Ajuda: Els segells -->
            <div class="nav-group">
                <span class="nav-title">
                    <i class="bi bi-shield-exclamation me-1"></i>
                    <?= __('ajuda.elssegells') ?>
                </span>
                <nav class="nav-sub">
                    <a class="nav-link" href="#significat_segells"><?= __('ajuda.significatsegells') ?></a>
                    <a class="nav-link" href="#caducar_redireccionar"><?= __('ajuda.caducarredireccionar') ?></a>
                </nav>
            </div>

            <!-- Ajuda: Subscripció i Vistos -->
            <div class="nav-group">
                <span class="nav-title">
                    <i class="bi bi-rocket-takeoff me-2"></i>
                    <?= __('ajuda.riders_titol') ?>
                </span>
                <nav class="nav-sub">
                    <a class="nav-link" href="#riders_vist"><?= __('ajuda.riders_vistos') ?></a>
                    <a class="nav-link" href="#riders_subscrit"><?= __('ajuda.riders_subscrit') ?></a>
                </nav>
            </div>

            <!-- Ajuda: Producció tècnica 
            <div class="nav-group">
                <span class="nav-title">
                    <i class="bi bi-gear-wide-connected me-2"></i>
                    Producció tècnica
                </span>
                <nav class="nav-sub">
                    <a class="nav-link" href="#">Els teus esdeveniments</a>
                </nav>
            </div>
            -->
            <!-- Ajuda: El visualitzador -->
            <div class="nav-group">
                <span class="nav-title">
                    <i class="bi bi-eye me-1"></i>
                    <?= __('ajuda.visualitzador') ?>
                </span>
                <nav class="nav-sub">
                    <a class="nav-link" href="#segell_qr"><?= __('ajuda.segellqr') ?></a>
                    <a class="nav-link" href="#validar_integritat"><?= __('ajuda.validarintegritat') ?></a>
                    <a class="nav-link" href="#riders_vistos"><?= __('nav.recent_riders') ?></a>
                </nav>
            </div>
            <!-- Ajuda: Altres -->
            <div class="nav-group">
                <span class="nav-title">
                    <i class="bi bi-envelope-at me-1"></i>
                    <?= __('ajuda.altresopcions') ?>
                </span>
                <nav class="nav-sub">
                    <a class="nav-link" href="#contacte"><?= __('ajuda.contacte') ?></a>
                </nav>
            </div>
          </div>
        </nav>
      </aside>

      <!-- Cos central -->
      <section class="col-12 col-lg-10">
        <div tabindex="0">
          <!-- Secció: Dades personal -->
          <article class="docs-section">
            <h2 class="h4"><?= __('nav.your_data') ?></h2>
            <!-- El teu ID -->
            <h3 id="id_dadespersonals" class="h5"><?= __('ajuda.elteuid') ?></h3>
            <p>
              <?= __('ajuda.elteuid.p1') ?>
              <a href="espai.php?seccio=dades"><?= __('nav.your_data') ?></a>.
            </p>
            <!-- Contrasenya -->
            <h3 id="canvi_contrasenya" class="h5"><?= __('ajuda.contrasenya') ?></h3>
            <p>
              <?= __('ajuda.elteuid.p2') ?>
              <a href="espai.php?seccio=dades"><?= __('nav.your_data') ?></a>. 
            </p>
            <!-- Publicar el teu telèfon -->
            <h3 id="publicar_telefon" class="h5"><?= __('ajuda.publicartelefon') ?></h3>
            <p>
              <?php printf(__("ajuda.publicartelefon.p1"), "espai.php?seccio=dades");?>
            </p>
            <!-- Rol Productor -->
            <h3 id="rol_productor" class="h5"><?= __('ajuda.productor') ?></h3>
            <p>
              <?php printf(__("ajuda.productor.p1"), "espai.php?seccio=dades");?>
            </p>
            <!-- Idioma -->
            <h3 id="canvi_idioma" class="h5"><?= __('ajuda.idioma') ?></h3>
            <p>
              <?php printf(__("ajuda.idioma.p1"), "espai.php?seccio=dades");?> 
            </p>
            <p><?= __('ajuda.idioma.p2') ?></p>
          </article>

          <!-- Secció: Riders -->
          <article class="docs-section">
            <h2 class="h4"><?= __('nav.your_riders') ?></h2>
            <!-- Pujar en rider -->
            <h3 id="pujar_rider" class="h5"><?= __('ajuda.pujarrider') ?></h3>
            <p><?php printf(__("ajuda.pujarrider.p1"), "espai.php?seccio=riders");?></p>
            <p><?= __('ajuda.pujarrider.p2') ?></p>
            <!-- La taula del rider -->
            <h3 id="taula_rider" class="h5"><?= __('ajuda.taularider') ?></h3>
            <p>
                <?= __('ajuda.taularider.p1') ?>
                <table class="table docs-table">
                <tbody>
                    <tr>
                    <th scope="row"><?=__('riders.table.col_id') ?></th>
                    <td><?= __('ajuda.taularider.t1') ?></td>
                    </tr>
                    <tr>
                    <th scope="row"><?= h(__('riders.table.col_desc')) ?></th>
                    <td><?= __('ajuda.taularider.t2') ?></td>
                    </tr>
                    <tr>
                    <th scope="row"><?= h(__('riders.table.col_ref')) ?></th>
                    <td><?= __('ajuda.taularider.t3') ?></td>
                    </tr>
                    <tr>
                    <th scope="row"><i class="bi bi-cloud-upload" title="<?= h(__('riders.table.col_uploaded')) ?>"></i></th>
                    <td><?= __('ajuda.taularider.t4') ?></td>
                    </tr>
                    <tr>
                    <th scope="row"><i class="bi bi-calendar2-week" title="<?= h(__('riders.table.col_published')) ?>"></i></th>
                    <td><?= __('ajuda.taularider.t5') ?></td>
                    </tr>
                    <tr>
                    <th scope="row"><i class="bi bi-heart" title="<?= h(__('riders.table.col_score')) ?>"></i></th>
                    <td><?= __('ajuda.taularider.t6') ?></td>
                    </tr>
                    <tr>
                    <th scope="row"><i class="bi bi-shield-shaded" title="<?= h(__('riders.table.col_seal')) ?>"></i></th>
                    <td><?= __('ajuda.taularider.t7') ?></td>
                    </tr>
                    <tr>
                    <th scope="row"><?= h(__('riders.table.col_redirect')) ?></th>
                    <td><?= __('ajuda.taularider.t8') ?></td>
                    </tr>
                </tbody>
                </table>
            </p>
            <!-- Les accions al rider -->
            <h3 id="accions_rider" class="h5"><?= __('ajuda.accionsrider') ?></h3>
            <p><?= __('ajuda.accionsrider.p1') ?></p>
              <table class="table docs-table">
                <tbody>
                  <tr>
                    <th scope="row"><button type="button"
                        class="btn btn-primary btn-sm meta-edit-btn">
                        <i class="bi bi-pencil-square"></i>
                      </button></th>
                    <td><?= __('ajuda.accionsrider.t1') ?></td>
                  </tr>
                  <tr>
                    <th scope="row">
                      <button type="button"
                        class="btn btn-primary btn-sm reupload-btn">
                          <i class="bi bi-arrow-repeat"></i>
                      </button>
                    </th>
                    <td><?= __('ajuda.accionsrider.t2') ?></td>
                  </tr>
                  <tr>
                    <th scope="row"><button type="button"
                         class="btn btn-primary btn-sm">
                         <i class="bi bi-lightning-charge"></i>
                      </button>
                    </th>
                    <td><?= __('ajuda.accionsrider.t3') ?></td> 
                  </tr>
                  <tr>
                    <th scope="row"><button type="button"
                         class="btn btn-primary btn-sm">
                         <i class="bi bi-robot"></i>
                      </button>
                    </th>
                    <td><?= __('ajuda.accionsrider.t4') ?></td> 
                  </tr>
                  <tr>
                    <th scope="row"><button type="button"
                         class="btn btn-primary btn-sm">
                         <i class="bi bi-person-check"></i>
                      </button>
                    </th>
                    <td><?= __('ajuda.accionsrider.t5') ?></td> 
                  </tr>
                  <tr>
                    <th scope="row"><button type="button"
                         class="btn btn-primary btn-sm">
                         <i class="bi bi-patch-check"></i>
                      </button>
                    </th>
                    <td><?= __('ajuda.accionsrider.t6') ?></td> 
                  </tr>
                 <tr>
                    <th scope="row"><button type="button"
                         class="btn btn-primary btn-sm">
                         <i class="bi bi-info-circle"></i>
                      </button>
                    </th>
                    <td><?= __('ajuda.accionsrider.t7') ?></td> 
                  </tr>
                  <tr>
                    <th scope="row"><button type="button"
                         class="btn btn-primary btn-sm">
                         <i class="bi bi-qr-code"></i>
                      </button>
                    </th>
                    <td><?= __('ajuda.accionsrider.t8') ?></td> 
                  </tr>
                  <tr>
                    <th scope="row"><button type="button"
                         class="btn btn-primary btn-sm">
                         <i class="bi bi-box-arrow-up-right"></i>
                      </button>
                    </th>
                    <td><?= __('ajuda.accionsrider.t9') ?></td> 
                  </tr>
                  <tr>
                    <th scope="row"><button type="button"
                         class="btn btn-primary btn-sm">
                         <i class="bi bi-eye"></i>
                      </button>
                    </th>
                    <td><?= __('ajuda.accionsrider.t10') ?></td> 
                  </tr>
                  <!-- De moment  no el posem
                  <tr>
                    <th scope="row"><button type="button"
                         class="btn btn-primary btn-sm">
                         <i class="bi bi-download"></i>
                      </button>
                    </th>
                    <td><?= __('ajuda.accionsrider.t11') ?></td> 
                  </tr>
                  -->
                  <tr>
                    <th scope="row"><button type="button"
                         class="btn btn-primary btn-sm">
                         <i class="bi bi-rocket-takeoff"></i>
                      </button>
                    </th>
                    <td><?= __('ajuda.accionsrider.t13') ?></td> 
                  </tr>
                  <tr>
                    <th scope="row"><button type="button"
                         class="btn btn-danger btn-sm">
                         <i class="bi bi-trash3"></i>
                      </button>
                    </th>
                    <td><?= __('ajuda.accionsrider.t12') ?></td> 
                  </tr>
                </tbody>
              </table>  
          <!-- Detall IA -->
           <h3 id="detalls_ia" class="h5"><?= __('ajuda.detallsia') ?></h3>
            <p><?= __('ajuda.detallsia.p1') ?></p>
            <table class="table docs-table">
              <tbody>
                <tr>
                   <th scope="row"><?= __('ajuda.detallsia.t1') ?></th>
                   <td><?= __('ajuda.detallsia.t2') ?></td> 
                 </tr>
                 <tr>
                   <th scope="row"><?= __('ajuda.detallsia.t3') ?></th>
                   <td><?= __('ajuda.detallsia.t4') ?></td> 
                 </tr>
                 <tr>
                   <th scope="row"><?= __('ajuda.detallsia.t5') ?></th>
                    <td><?= __('ajuda.detallsia.t6') ?></td> 
                 </tr>                    <tr>
                   <th scope="row"><?= __('ajuda.detallsia.t7') ?></th>
                   <td><?= __('ajuda.detallsia.t8') ?></td> 
                 </tr>
              </tbody>
            </table>
          </article>

          <article class="docs-section">
            <h2 class="h4"><?= __('ajuda.elssegells') ?></h2>
            <!-- Els Segells -->
            <h3 id="significat_segells" class="h5"><?= __('ajuda.significatsegells') ?></h3>
            <p><?= __('ajuda.elssegells.p1') ?></p>
            <p><?= __('ajuda.elssegells.p2') ?></p>
            <table class="table docs-table">
                <tbody>
                  <tr>
                    <th scope="row"><i class="bi bi-shield-exclamation text-warning"></i></th>
                    <td><?= __('ajuda.elssegells.t1') ?></td>
                  </tr>
                  <tr>
                    <th scope="row"><i class="bi bi-shield-fill-check" style="color: green;"></i></th>
                    <td><?= __('ajuda.elssegells.t2') ?></td>
                  </tr>
                  <tr>
                    <th scope="row"><i class="bi bi-shield-x text-danger"></i></th>
                    <td><?= __('ajuda.elssegells.t3') ?></td>
                    </tr>
                    <tr>
                    <th scope="row"><i class="bi bi-shield-fill-x text-danger"></i></th>
                    <td><?= __('ajuda.elssegells.t4') ?></td>
                    </tr>
                </tbody>
            </table>
            <!-- Caducar/Redireccionar -->
            <h3 id="caducar_redireccionar" class="h5"><?= __('ajuda.caducarredireccionar') ?></h3>
            <p><?= __('ajuda.caducarredirecionar.p1') ?></p>
            <p><?= __('ajuda.caducarredirecionar.p2') ?></p>
            <p><?= __('ajuda.caducarredirecionar.p3') ?></p>
            <p><?= __('ajuda.caducarredirecionar.p4') ?></p>
          </article>

          <!-- Secció: Riders vistos i subscrits -->
          <article class="docs-section">
            <h2 class="h4"><?= __('ajuda.riders_titol') ?></h2>
            <!-- Riders vistos -->
            <h3 id="riders_vist" class="h5"><?= __('ajuda.riders_vistos') ?></h3>
            <p>
              <?= __('ajuda.riders_vistos.p1') ?>
            </p>
            <!-- Riders subscrit -->
            <h3 id="riders_subscrit" class="h5"><?= __('ajuda.riders_subscrit') ?></h3>
            <p>
              <?= __('ajuda.riders_subscrit.p1') ?>
            </p>
          </article>

          <!-- Secció: El visualitzador -->
          <article class="docs-section">
            <h2 class="h4"><?= __('ajuda.visualitzador') ?></h2>
            <img src="img/Mostra_validat.png" class="rounded mx-auto d-block" alt="Mostra segell validat" style="max-width:350px; margin: 15px;">
            <!-- Segell i QR -->
            <h3 id="segell_qr" class="h5"><?= __('ajuda.segellqr') ?></h3>
            <p><?= __('ajuda.segellqr.p1') ?></p>
            <p><?= __('ajuda.segellqr.p2') ?></p>
            <p><?= __('ajuda.segellqr.p3') ?></p>
            <!-- Integritat del rider -->
            <h3 id="validar_integritat" class="h5"><?= __('ajuda.validarintegritat') ?></h3>
            <p><?= __('ajuda.validarintegritat.p1') ?></p>
            <p><?= __('ajuda.validarintegritat.p2') ?></p>
            <p><?= __('ajuda.validarintegritat.p3') ?></p>
            <p><?= __('ajuda.validarintegritat.p4') ?></p>
            <!-- Riders vistos pàgina -->
            <h3 id="riders_vistos" class="h5"><?= __('nav.recent_riders') ?></h3>
            <p><?= __('ajuda.ridersvistos.p1') ?></p>
          </article>
          <article class="docs-section">
            <h2 id="contacte" class="h4"><?= __('ajuda.contacte') ?></h2>
            <!-- CONTACTE -->
            <p><?= __('ajuda.contacte.p1') ?></p>
          </article>
        </div>
      </section>
    </div>
  </div>
<!-- JS Scrollspy menú lateral -->
<script>
document.addEventListener('DOMContentLoaded', () => {
  // 1) Indexa enllaços del sidebar per id
  const sideLinks = Array.from(document.querySelectorAll('#docsNav .nav-sub .nav-link'));
  if (!sideLinks.length) return;

  const byId = new Map();
  sideLinks.forEach(a => {
    const id = (a.getAttribute('href') || '').replace(/^#/, '');
    if (id) byId.set(id, a);
  });

  // 2) Seccions observables (els H3 amb id dins del cos de docs)
  const sections = Array.from(document.querySelectorAll('.docs-section h3[id]'))
    .filter(h => byId.has(h.id));
  if (!sections.length) return;

  // 3) Utilitat per activar/desactivar l’enllaç corresponent
  function activate(id) {
    sideLinks.forEach(a => a.classList.remove('active'));
    const link = byId.get(id);
    if (link) {
      link.classList.add('active');
      sideLinks.forEach(a => a.removeAttribute('aria-current'));
      link.setAttribute('aria-current', 'true');
    }
  }

  // 4) Observer: quan una secció entra a viewport, marquem el seu link
  // rootMargin eleva el “punt d’activació” per evitar saltets
  const observer = new IntersectionObserver((entries) => {
    // triem la secció més amunt i visible
    const visible = entries
      .filter(e => e.isIntersecting)
      .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
    if (visible.length) activate(visible[0].target.id);
  }, { root: null, rootMargin: `-${(parseInt(getComputedStyle(document.documentElement)
              .getPropertyValue('--nav-offset'))||60)+20}px 0px -60% 0px`, threshold: 0.05 });

  sections.forEach(s => observer.observe(s));

  // 5) També marquem en clicar (evita parpelleigs fins que l’observer reacciona)
  sideLinks.forEach(a => {
    a.addEventListener('click', () => {
      const id = (a.getAttribute('href') || '').replace('#','');
      if (id) activate(id);
    });
  });

  // 6) Activació inicial (si hi ha hash a l’URL)
  if (location.hash) {
    const id = location.hash.replace('#','');
    if (byId.has(id)) activate(id);
  }
});
</script>
<?php require_once __DIR__ . '/parts/footer.php'; ?>