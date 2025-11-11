<?php
/* ── PHP mínim inicial  ──────────────────────────────────────────── */
declare(strict_types=1);
require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/i18n.php';
require_once __DIR__ . '/php/middleware.php';

/* ── Head + Nav ──────────────────────────────────────────── */
require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';
$lang = $_SESSION['lang'] ?? 'ca';
if (!in_array($lang, ['ca', 'es', 'en'], true)) { $lang = 'ca'; }
/* ── <body> i <main> ja estan aplicats ── */
?>
<div class="container mb-5 legal"> 
<?php switch ($lang): case 'es': ?>
  <div class="d-flex justify-content-between align-items-center mb-1">
        <h4 class="border-bottom border-1 border-secondary pb-2 w-100">
        Política de cookies
        </h4>
    </div>
    <p class="small">Última actualización: 11/11/2025</p>

  <h5>1. Qué son las cookies</h5>
  <p>Las cookies (galletas) son pequeños archivos de texto que los sitios web guardan en tu navegador para recordar información sobre tu visita. Se usan para mantener sesiones, preferencias o mejorar la experiencia del usuario.</p>

  <h5>2. Tipos de cookies utilizadas</h5>
  <ul>
    <li><strong>Cookies esenciales</strong>: necesarias para el funcionamiento básico de la plataforma, como la sesión de usuario o la selección de idioma.</li>
    <li><strong>Cookies de preferencia</strong>: guardan ajustes opcionales (por ejemplo, idioma o vista preferida).</li>
    <li><strong>Cookies de terceros</strong>: solo se utilizan si el usuario accede a servicios externos (Stripe, Cloudflare, etc.).</li>
  </ul>

  <h5>3. Base legal</h5>
  <p>El uso de cookies esenciales se basa en el interés legítimo de garantizar el funcionamiento técnico del sitio. Las cookies opcionales solo se instalan tras el consentimiento del usuario.</p>

  <h5>4. Cómo gestionar las cookies</h5>
  <p>Puedes configurar o eliminar cookies directamente desde tu navegador. A continuación algunos enlaces de ayuda:</p>
  <ul>
    <li><a href="https://support.google.com/chrome/answer/95647?hl=es" target="_blank">Google Chrome</a></li>
    <li><a href="https://support.mozilla.org/es/kb/Borrar%20cookies" target="_blank">Mozilla Firefox</a></li>
    <li><a href="https://support.apple.com/es-es/guide/safari/sfri11471/mac" target="_blank">Safari</a></li>
  </ul>

  <h5>5. Cambios</h5>
  <p>Kinosonik Riders puede actualizar esta política en cualquier momento. Los cambios se publicarán en esta misma página.</p>

  <p><strong>Kinosonik Riders</strong> — riders@kinosonik.com</p>

<?php break; case 'en': ?>
  <div class="d-flex justify-content-between align-items-center mb-1">
        <h4 class="border-bottom border-1 border-secondary pb-2 w-100">
        Cookie Policy
        </h4>
    </div>
    <p class="small">Last update: 11/11/2025</p>

  <h5>1. What are cookies</h5>
  <p>Cookies are small text files stored by websites in your browser to remember information about your visit. They are used to maintain sessions, preferences, or enhance the user experience.</p>

  <h5>2. Types of cookies used</h5>
  <ul>
    <li><strong>Essential cookies</strong>: required for the basic operation of the platform, such as session or language selection.</li>
    <li><strong>Preference cookies</strong>: remember optional settings (e.g., language, view preferences).</li>
    <li><strong>Third-party cookies</strong>: only used when accessing external services (Stripe, Cloudflare, etc.).</li>
  </ul>

  <h5>3. Legal basis</h5>
  <p>Essential cookies are processed under legitimate interest to ensure site functionality. Optional cookies are only stored after user consent.</p>

  <h5>4. Managing cookies</h5>
  <p>You can manage or delete cookies directly in your browser. Help links:</p>
  <ul>
    <li><a href="https://support.google.com/chrome/answer/95647?hl=en" target="_blank">Google Chrome</a></li>
    <li><a href="https://support.mozilla.org/en-US/kb/clear-cookies-and-site-data-firefox" target="_blank">Mozilla Firefox</a></li>
    <li><a href="https://support.apple.com/guide/safari/sfri11471/mac" target="_blank">Safari</a></li>
  </ul>

  <h5>5. Changes</h5>
  <p>Kinosonik Riders may update this policy at any time. Updates will be published on this page.</p>

  <p><strong>Kinosonik Riders</strong> — riders@kinosonik.com</p>

<?php break; default: ?>

  <div class="d-flex justify-content-between align-items-center mb-1">
        <h4 class="border-bottom border-1 border-secondary pb-2 w-100">
        Política de galetes
        </h4>
    </div>
    <p class="small">Última actualització: 11/11/2025</p>

  <h5>1. Què són les galetes</h5>
  <p>Les galetes (cookies) són petits fitxers de text que els llocs web guarden al navegador per recordar informació sobre la visita. S’utilitzen per mantenir sessions, preferències o millorar l’experiència d’usuari.</p>

  <h5>2. Tipus de galetes utilitzades</h5>
  <ul>
    <li><strong>Galetes essencials</strong>: necessàries per al funcionament bàsic de la plataforma, com la sessió o l’idioma.</li>
    <li><strong>Galetes de preferència</strong>: recorden ajustos opcionals (idioma o vista preferida).</li>
    <li><strong>Galetes de tercers</strong>: només s’utilitzen si l’usuari accedeix a serveis externs (Stripe, Cloudflare, etc.).</li>
  </ul>

  <h5>3. Base jurídica</h5>
  <p>L’ús de galetes essencials es basa en l’interès legítim de garantir el funcionament tècnic del web. Les galetes opcionals només s’instal·len amb el consentiment de l’usuari.</p>

  <h5>4. Com gestionar les galetes</h5>
  <p>Pots configurar o eliminar galetes directament des del navegador. Enllaços d’ajuda:</p>
  <ul>
    <li><a href="https://support.google.com/chrome/answer/95647?hl=ca" target="_blank">Google Chrome</a></li>
    <li><a href="https://support.mozilla.org/ca/kb/esborrar-cookies" target="_blank">Mozilla Firefox</a></li>
    <li><a href="https://support.apple.com/ca-es/guide/safari/sfri11471/mac" target="_blank">Safari</a></li>
  </ul>

  <h5>5. Canvis</h5>
  <p>Kinosonik Riders pot actualitzar aquesta política en qualsevol moment. Les modificacions es publicaran en aquesta mateixa pàgina.</p>

  <p><strong>Kinosonik Riders</strong> — riders@kinosonik.com</p>

<?php endswitch; ?>
</div>

<?php require_once __DIR__ . '/parts/footer.php'; ?>
