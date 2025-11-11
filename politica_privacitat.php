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
<div class="container mb-5 legal"> <!-- Container inicial, de w-75 per la majoria de casos -->
    <?php switch ($lang): case 'es': ?>
    <!-- CASTELLÀ -->
    <div class="d-flex justify-content-between align-items-center mb-1">
        <h4 class="border-bottom border-1 border-secondary pb-2 w-100">
        Política de privacitat
        </h4>
    </div>
    <p class="small">Última actualización: 11/11/2025</p>
      <h5>1. Responsable del tratamiento</h5>
    <p>El responsable de los datos es <strong>Kinosonik Riders</strong>, con sede operativa en España y contacto en <code>riders@kinosonik.com</code>.</p>

    <h5>2. Finalidades del tratamiento</h5>
    <ul>
        <li>Gestión de usuarios (alta, autenticación y mantenimiento de la cuenta).</li>
        <li>Análisis técnico de documentos (“riders”) mediante sistemas propios e inteligencia artificial.</li>
        <li>Comunicaciones relacionadas con el uso de la plataforma.</li>
        <li>Gestión de suscripciones y pagos (procesados de forma segura mediante Stripe).</li>
        <li>Control interno, estadísticas y mejora del servicio.</li>
    </ul>

    <h5>3. Base jurídica</h5>
    <p>El tratamiento se basa en la ejecución del contrato, el consentimiento del usuario y el cumplimiento de obligaciones legales.</p>

    <h5>4. Conservación de los datos</h5>
    <p>Los datos se conservan mientras el usuario mantenga su cuenta activa y, posteriormente, durante los plazos legales aplicables.</p>

    <h5>5. Destinatarios y transferencias</h5>
    <p>Servidores en <strong>Hetzner (UE)</strong>, almacenamiento en <strong>Cloudflare R2</strong> y pagos gestionados por <strong>Stripe</strong>. No hay transferencias fuera del EEE sin garantías adecuadas.</p>

    <h5>6. Derechos del usuario</h5>
    <p>Puede ejercer los derechos de acceso, rectificación, supresión, limitación, oposición y portabilidad escribiendo a <code>riders@kinosonik.com</code>.</p>

    <h5>7. Seguridad de la información</h5>
    <p>Se aplican medidas técnicas y organizativas para garantizar la confidencialidad, integridad y disponibilidad de los datos.</p>

    <h5>8. Menores de edad</h5>
    <p>El servicio está orientado a profesionales del sector musical y no a menores de 16 años. No se solicitan datos de edad ni se realizan comprobaciones específicas.</p>

    <h5>9. Modificaciones</h5>
    <p>Esta política puede actualizarse y será efectiva desde su publicación en esta página.</p>

    <p><strong>Kinosonik Riders</strong> — riders@kinosonik.com</p>

    <?php break; case 'en': ?>
    <!-- ANGLÈS -->
     <div class="d-flex justify-content-between align-items-center mb-1">
        <h4 class="border-bottom border-1 border-secondary pb-2 w-100">
        Privacy Policy
        </h4>
    </div>
    <p class="small">Last update: 11/11/2025</p>

    <h5>1. Data Controller</h5>
  <p>The controller is <strong>Kinosonik Riders</strong>, based in Spain, contact at <code>riders@kinosonik.com</code>.</p>

  <h5>2. Purpose of processing</h5>
  <ul>
    <li>User management (registration, authentication, and account maintenance).</li>
    <li>Technical analysis of documents (“riders”) using proprietary systems and AI modules.</li>
    <li>Service-related communications and notifications.</li>
    <li>Subscription and payment management through secure platforms such as Stripe.</li>
    <li>Internal control, statistics, and service improvement.</li>
  </ul>

  <h5>3. Legal basis</h5>
  <p>Processing is based on contract execution, user consent, and legal obligations.</p>

  <h5>4. Data retention</h5>
  <p>Data are kept while the account is active and later for the legally required period.</p>

  <h5>5. Recipients and transfers</h5>
  <p>Servers at <strong>Hetzner (EU)</strong>, file storage at <strong>Cloudflare R2</strong>, and payments via <strong>Stripe</strong>. No data are transferred outside the EEA without proper safeguards.</p>

  <h5>6. User rights</h5>
  <p>Users may exercise access, rectification, erasure, restriction, objection, and portability rights by emailing <code>riders@kinosonik.com</code>.</p>

  <h5>7. Information security</h5>
  <p>Kinosonik Riders applies appropriate technical and organizational measures to protect confidentiality, integrity, and availability of data.</p>

  <h5>8. Minors</h5>
  <p>The service targets music industry professionals and is not intended for minors under 16. No age-related data are collected or verified.</p>

  <h5>9. Changes</h5>
  <p>This policy may be updated and becomes effective upon publication.</p>

  <p><strong>Kinosonik Riders</strong> — riders@kinosonik.com</p>

<?php break; default: ?>
    <!-- CATALÀ -->
     <div class="d-flex justify-content-between align-items-center mb-1">
        <h4 class="border-bottom border-1 border-secondary pb-2 w-100">
        Política de privacitat
        </h4>
    </div>
    <p class="small">Última actualització: 11/11/2025</p>

    <h5>1. Responsable del tractament</h5>
  <p>El responsable de les dades és <strong>Kinosonik Riders</strong>, amb domicili operatiu a Espanya i contacte a <code>riders@kinosonik.com</code>.</p>

  <h5>2. Finalitats del tractament</h5>
  <ul>
    <li>Gestió d’usuaris (alta, autenticació i manteniment del compte).</li>
    <li>Anàlisi tècnica de documents (“riders”) amb sistemes propis i mòduls d’intel·ligència artificial.</li>
    <li>Comunicacions relacionades amb l’ús de la plataforma.</li>
    <li>Gestió de subscripcions i pagaments (mitjançant Stripe).</li>
    <li>Control intern, estadístiques i millora del servei.</li>
  </ul>

  <h5>3. Base jurídica</h5>
  <p>El tractament es fonamenta en l’execució del contracte, el consentiment de l’usuari i el compliment d’obligacions legals.</p>

  <h5>4. Conservació de les dades</h5>
  <p>Les dades es mantenen mentre l’usuari tingui el compte actiu i, després, durant els terminis legals corresponents.</p>

  <h5>5. Destinataris i transferències</h5>
  <p>Servidors a <strong>Hetzner (UE)</strong>, emmagatzematge a <strong>Cloudflare R2</strong> i pagaments via <strong>Stripe</strong>. No hi ha transferències fora de l’EEE sense garanties adequades.</p>

  <h5>6. Drets de les persones usuàries</h5>
  <p>Es poden exercir els drets d’accés, rectificació, supressió, limitació, oposició i portabilitat escrivint a <code>riders@kinosonik.com</code>.</p>

  <h5>7. Seguretat de la informació</h5>
  <p>Kinosonik Riders aplica mesures tècniques i organitzatives per garantir la confidencialitat, integritat i disponibilitat de les dades.</p>

  <h5>8. Menors d’edat</h5>
  <p>Els serveis estan pensats per a professionals del sector musical i no per a menors de 16 anys. No es demanen dades d’edat ni es fan comprovacions específiques.</p>

  <h5>9. Modificacions</h5>
  <p>Aquesta política pot actualitzar-se i serà efectiva des de la seva publicació en aquesta pàgina.</p>

  <p><strong>Kinosonik Riders</strong> — riders@kinosonik.com</p>

<?php endswitch; ?>

</div>

<?php
/* ── Footer ──────────────────────────────────────────── */
require_once __DIR__ . '/parts/footer.php';
?>