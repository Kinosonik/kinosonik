<?php
declare(strict_types=1);
require_once __DIR__ . '/php/preload.php'; // ✅ carrega helpers i sessió
require_once __DIR__ . '/php/i18n.php';
require_once __DIR__ . '/php/middleware.php';
require_once __DIR__ . '/php/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!function_exists('ks_user_id')) {
  function ks_user_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
  }
}
if (!function_exists('ks_is_admin')) {
  function ks_is_admin(): bool {
    return (($_SESSION['tipus_usuari'] ?? '') === 'admin');
  }
}

ks_require_role('tecnic','productor','admin');

if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  echo '<div class="container my-4"><div class="alert alert-danger">ID invàlid.</div></div>';
  return;
}

// --- carrega rider amb permís (propietari o admin)
$pdo = db();
$userId  = ks_user_id();
$isAdmin = ks_is_admin();

$sql = 'SELECT ID_Rider, ID_Usuari, Descripcio, Nom_Arxiu
        FROM Riders
        WHERE ID_Rider = ?
        LIMIT 1';
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$rider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rider) {
  echo '<div class="container my-4"><div class="alert alert-warning">Rider inexistent.</div></div>';
  return;
}

// Permisos: si no ets admin, només pots veure els teus
if (!$isAdmin && (int)$rider['ID_Usuari'] !== (int)$userId) {
  http_response_code(403);
  echo '<div class="container my-4"><div class="alert alert-danger">No tens permís per veure aquest rider.</div></div>';
  return;
}

// Títol i descripció per a la capçalera
$riderTitle = trim((string)($rider['Descripcio'] ?? ''));
if ($riderTitle === '') $riderTitle = (string)($rider['Nom_Arxiu'] ?? '');
if ($riderTitle === '') $riderTitle = '#'.$id;

$riderDesc  = ''; // no tens camp "títol" separat; ja usem Descripcio com a títol si existeix

// --- fallbacks segurs / CSRF
$BASE = defined('BASE_PATH') ? BASE_PATH : '/';
$__csrf = $_SESSION['csrf'] ?? '';
if ($__csrf === '') { $_SESSION['csrf'] = bin2hex(random_bytes(32)); $__csrf = $_SESSION['csrf']; }
?>

<div class="container w-75">
  <!-- Bloc d'avís permanent -->
  <div class="w-100 mb-3 small text-warning"
   style="background:rgba(255,193,7,0.15);
        border-left:3px solid #ffc107;
        padding:25px 18px;">
    <strong class="text-warning"><?= h(__('ia.titol.avis')) ?>:</strong>
    <?= h(__('ia.titol.avis.p1')) ?>
  </div>
  <!-- Títol -->
  <div class="d-flex justify-content-between align-items-center mb-1">
    <h4 class="border-bottom border-1 border-secondary pb-2 w-100">
      <i class="bi bi-lightning text-warning"></i>&nbsp;
      <?= h(__('ia.titol.analitzant')) ?>
    </h4>
  </div>

  <!-- Informació bàsica del rider -->
  <?php
  $title = trim((string)($rider['Nom_Arxiu'] ?? ''));
  if ($title === '') $title = '#'.$id;
  $subtitle = trim((string)($rider['Descripcio'] ?? ''));
  ?>
  <div class="d-flex mb-2 flex-column flex-sm-row small text-secondary">
    <div class="w-100">
      <div class="row row-cols-auto justify-content-start text-start">
        <!-- Descripció rider -->
        <div class="col"><?= h(__('riders.upload.desc_label')) ?>: <span class="text-light"><?= h($riderTitle) ?></span></div>
        <!-- ID Rider -->
        <div class="col">ID Rider: <span class="text-light"><?= h((string)$rider['ID_Rider']) ?></span></div>
        <!-- Arxiu PDF -->
        <div class="col"><?= h(__('ia.titol.pdf')) ?>: <?= h($title) ?></div>
      </div>
    </div>
  </div>
  
  <!-- PROGRÉS -->
  <div class="card border-0 bg-light-subtle mt-3 mb-3">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <div class="small text-muted" id="aiStage"><?= h(__('ia.titol.inicialitzant')) ?></div>
        <div class="small text-secondary" id="aiElapsed" aria-live="polite" aria-atomic="true" title="<?= h(__('ia.titol.durada')) ?>">
          <?= h(__('ia.titol.durada')) ?> <span id="aiElapsedVal">00:00</span>
        </div>
      </div>
      <div class="progress mb-3" role="progressbar" aria-label="<?= h(__('ia.titol.durada')) ?>" aria-valuemin="0" aria-valuemax="100" style="height:2px;">
        <div id="aiBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
      </div>
      <div class="small border rounded p-2 bg-transparent"
           style="min-height:160px; max-height:280px; overflow:auto"
           id="aiLogs" aria-live="polite">
        <em class="text-secondary"><?= h(__('ia.titol.esperant')) ?></em>
      </div>
    </div>
  </div>

  <!-- Cuadre de resultats NOU -->
  <div id="aiResults" class="card border-0 bg-light-subtle mt-3 mb-3" style="display:none;">
    <div class="card-header bg-transparent pb-0">
      <div class="d-flex align-items-center justify-content-between">
        <h4 class="mb-2"><?= h(__('ia.titol.resultats')) ?></h4>
      </div>
    </div>
    <div class="card-body pt-2">
      <div class="mb-2">
        <div class="small text-secondary mb-1">
          <?= h(__('ia.titol.calificacio')) ?>:
          <strong><span id="aiScoreBadge" style="display:none;"></span></strong>
        </div>
      </div>
      <!-- Comentaris -->
      <div class="mb-2" id="aiCommentsWrap" style="display:none;">
        <div class="small text-secondary mb-1"><?= h(__('ia.titol.comentaris')) ?></div>
        <ul id="aiComments" class="small mb-0 text-light"></ul>
      </div>
      <!-- Bloc suggerit -->
      <div class="mb-2" id="aiSuggestionWrap" style="display:none;">
        <div class="small text-secondary mb-1"><?= h(__('ia.titol.bloc')) ?></div>
        <pre id="aiSuggestion" class="small text-light bg-dark-subtle rounded p-2" style="white-space:pre-wrap; word-break:break-word;"></pre>
      </div>
    </div>
  </div>
      
  <!-- Botons de retorn -->
  <div class="border-0 mt-3 mb-3">
    <div class="d-flex justify-content-end gap-2 pb-0">
      <a href="<?= h($BASE) ?>espai.php?seccio=riders"
        class="btn btn-primary" id="btnBack" style="display:none;">
        <i class="bi bi-file-earmark-text"></i>
        <?= h(__('ia.titol.torna')) ?>
      </a>
      <a href="#"
        class="btn btn-primary" id="btnReport" style="display:none;"
        rel="noopener">
        <i class="bi bi-robot"></i>
        <?= h(__('ia.titol.informe')) ?>
      </a>
    </div>
  </div>
</div>

<meta name="csrf-token" content="<?= h($__csrf) ?>">

<script>
(function(){
  const id    = <?= (int)$id ?>;
  const csrf  = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  let pollTimer=null, clockTimer=null, lastLen=0, paused=document.hidden===true;
  let startedAtMs=null, jobUid=null, finished=false;

  const $stage=document.getElementById('aiStage');
  const $bar=document.getElementById('aiBar');
  const $logs=document.getElementById('aiLogs');
  const $back=document.getElementById('btnBack');
  const $report=document.getElementById('btnReport');
  const $elapsedWrap=document.getElementById('aiElapsed');
  const $elapsedVal=document.getElementById('aiElapsedVal');
  const $resBox=document.getElementById('aiResults');
  const $scoreBad=document.getElementById('aiScoreBadge');
  const $cWrap=document.getElementById('aiCommentsWrap');
  const $sWrap=document.getElementById('aiSuggestionWrap'); 

  // --- cache (per sobreviure reload) ---
  const KJOB = (id)=>`ai_job_${id}`;
  const KPOLL= (id)=>`ai_poll_${id}`;
  const KSTART=(id)=>`ai_started_ms_${id}`;
  function loadCache(){
    const job  = sessionStorage.getItem(KJOB(id));
    const poll = sessionStorage.getItem(KPOLL(id));
    const t    = sessionStorage.getItem(KSTART(id));
    return { job: job||null, poll: poll||null, started_at_ms: t?parseInt(t,10):null };
  }
  function saveCache(o){
    if (o.job)  sessionStorage.setItem(KJOB(id), o.job);
    if (o.poll) sessionStorage.setItem(KPOLL(id), o.poll);
    if (Number.isFinite(o.started_at_ms)) sessionStorage.setItem(KSTART(id), String(o.started_at_ms));
  }
  function clearCache(){
    sessionStorage.removeItem(KJOB(id));
    sessionStorage.removeItem(KPOLL(id));
    sessionStorage.removeItem(KSTART(id));
  }

  // --- utilitats UI ---
  function setStage(s){ $stage.textContent = s||''; }
  function setPct(p){ const v=Math.max(0,Math.min(100,p|0)); $bar.style.width=v+'%'; $bar.setAttribute('aria-valuenow', String(v)); }
  function appendLog(lines){
    if (!Array.isArray(lines)||!lines.length) return;
    const atEnd = $logs.scrollTop + $logs.clientHeight >= $logs.scrollHeight - 4;
    if ($logs.firstElementChild && $logs.firstElementChild.tagName==='EM') $logs.innerHTML='';
    for (const t of lines){ const div=document.createElement('div'); div.textContent=String(t); $logs.appendChild(div); }
    if (atEnd) $logs.scrollTop = $logs.scrollHeight;
  }
  function fmtDuration(ms){ const s=Math.max(0,Math.floor(ms/1000)); const h=(s/3600)|0, m=((s%3600)/60)|0, ss=s%60; const mm=String(m).padStart(2,'0'), sss=String(ss).padStart(2,'0'); return h>0?`${h}:${mm}:${sss}`:`${mm}:${sss}`; }
  function startClock(t){ startedAtMs=t||Date.now(); if (clockTimer) clearInterval(clockTimer); clockTimer=setInterval(()=>{ if (!startedAtMs) return; $elapsedVal.textContent=fmtDuration(Date.now()-startedAtMs); },1000); $elapsedVal.textContent=fmtDuration(Date.now()-startedAtMs); $elapsedWrap.classList.remove('text-muted'); }
  function stopClock(){ if (clockTimer) { clearInterval(clockTimer); clockTimer=null; } }
  document.addEventListener('visibilitychange', ()=>{ paused=document.hidden; });
  window.addEventListener('beforeunload', ()=>{ if (pollTimer) clearInterval(pollTimer); if (clockTimer) clearInterval(clockTimer); });

  function finish(){
    finished=true; stopClock();
    if (pollTimer) { clearInterval(pollTimer); pollTimer=null; }
    clearCache(); // evita enganxar-nos a polls antics
    $bar.classList.remove('progress-bar-animated','progress-bar-striped');
    $back.style.display='inline-block';
    if (jobUid && $report){ $report.href = '<?= h($BASE) ?>espai.php?seccio=ia_detail&job=' + encodeURIComponent(jobUid); $report.style.display='inline-block'; $report.setAttribute('target','_blank'); }
  }

  function renderResults(j){
  let any = false;

  // Score
  if (typeof j.score === 'number' && $scoreBad) {
    $scoreBad.textContent = `${j.score}/100`;
    $scoreBad.style.display = 'inline-block';
    any = true;
  }

  // Comentaris
  const comments = Array.isArray(j.comments) ? j.comments : [];
  if (comments.length && $cWrap) {
    const ul = document.getElementById('aiComments');
    ul.innerHTML = '';
    comments.forEach(t => {
      const li = document.createElement('li');
      li.textContent = String(t);
      ul.appendChild(li);
    });
    $cWrap.style.display = '';
    any = true;
  }

  // Bloc suggerit (accepta 'suggestion' o 'suggestion_block')
  const sug = (typeof j.suggestion === 'string' && j.suggestion.trim())
           || (typeof j.suggestion_block === 'string' && j.suggestion_block.trim())
           || '';
  if (sug && $sWrap) {
    const $pre = document.getElementById('aiSuggestion');
    $pre.textContent = sug;
    $sWrap.style.display = '';
    any = true;
  }

  if (any) $resBox.style.display = '';
}

  function poll(url){
    if (pollTimer) clearInterval(pollTimer);    // <-- evita múltiples intervals
    pollTimer = setInterval(()=>{
      if (paused || finished) return;
      fetch(url, {cache:'no-store', credentials:'same-origin'})
        .then(r=>r.json())
        .then(j=>{
          if (!j) return;
          if (!startedAtMs && Number.isFinite(j.started_at_ms)) startClock(j.started_at_ms);
          if (j.job && !jobUid) jobUid = j.job;
          if (j.gone===true){ setStage('Sessió d’anàlisi expirada.'); appendLog(['— El procés ja no està disponible per consultar.']); finish(); return; }
          if (j.pending){ setStage('Arrencant…'); return; }
          if (typeof j.pct==='number') setPct(j.pct);
          if (j.stage) setStage(j.stage);
          if (Array.isArray(j.logs) && j.logs.length>lastLen){ appendLog(j.logs.slice(lastLen)); lastLen=j.logs.length; }
          if (j.stale && !j.done) appendLog(['— Avís: el procés sembla aturat (stale).']);
          if (j.done || j.error){
            if (typeof j.score==='number') appendLog([`— Score: ${j.score}/100`]);
            if (j.error) appendLog(['— ERROR: '+j.error]);
            try { renderResults(j); } catch {}
            finish();
          }
        })
        .catch(()=>{ /* reintenta al següent tick */ });
    }, 800);
  }

  async function findPollTolerant(){
    const urls = [
      '<?= h($BASE) ?>php/ia_status.php?latest=1&id=' + encodeURIComponent(id),
      '<?= h($BASE) ?>php/ia_status.php?id=' + encodeURIComponent(id),
    ];
    for (const u of urls){
      const r = await fetch(u, {cache:'no-store', credentials:'same-origin'}).catch(()=>null);
      if (!r || !r.ok) continue;
      const j = await r.json().catch(()=>null);
      if (j && j.ok && j.found && j.poll) return j;
    }
    return null;
  }

  // ==== INIT ====
  (function init(){
    if (!csrf){ setStage('Error: falta el token de seguretat (CSRF).'); appendLog(['ERROR: Falta el token CSRF. Torna enrere i reintenta.']); setPct(100); finish(); return; }

    const c = loadCache();
    if (c && c.poll) {
      if (c.job) jobUid = c.job;         // ✅ guarda el job per poder pintar el botó al finish()
      setStage('Reprenent l’anàlisi…');
      if (Number.isFinite(c.started_at_ms)) startClock(c.started_at_ms);
      poll(c.poll);
      return;
    }

    setStage('Comprovant si hi ha un anàlisi en curs…');
    fetch('<?= h($BASE) ?>php/ia_status.php?latest=1&id=' + encodeURIComponent(id), {cache:'no-store', credentials:'same-origin'})
      .then(r=>r.json().catch(()=>({})).then(j=>({ok:r.ok, j})))
      .then(({ok,j})=>{
        if (ok && j && j.ok && j.found && j.poll){
          if (j.job) jobUid = j.job;         // ✅ captura el job
          const pollUrl = j.poll.startsWith('http') ? j.poll : (new URL(j.poll, location.origin)).toString();
          saveCache({ job: j.job||null, poll: pollUrl, started_at_ms: j.started_at_ms||null });
          if (Number.isFinite(j.started_at_ms)) startClock(j.started_at_ms);
          setStage('Reprenent l’anàlisi…');
          poll(pollUrl);
          return;
        }
        startNewJob(); // crear job si no n’hi ha
      })
      .catch(()=> startNewJob());
  })();

  // ==== START JOB ====
  async function startNewJob(){
    setStage('Inicialitzant anàlisi…');
    const body = new URLSearchParams({ id:String(id), csrf });

    const r = await fetch('<?= h($BASE) ?>php/ai_start.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      credentials:'same-origin',
      body
    }).catch(()=>null);

    if (!r){ setStage('Error iniciant l’anàlisi'); appendLog(['ERROR: xarxa']); setPct(100); finish(); return; }

    let j={}; try { j = await r.json(); } catch {}

    if (r.status===409){
      setStage('En cua…'); appendLog(['— Job ja en cua o en marxa. Obrint canal…']);
      const found = await findPollTolerant();
      if (found && found.poll){
        jobUid = (found.job || j.job || jobUid || null);  // ✅ assegura jobUid
        const pollUrl = found.poll.startsWith('http') ? found.poll : (new URL(found.poll, location.origin)).toString();
        saveCache({ job: jobUid, poll: pollUrl, started_at_ms: found.started_at_ms||null });
        if (Number.isFinite(found.started_at_ms)) startClock(found.started_at_ms);
        setStage('Analitzant…'); poll(pollUrl); return;
      }
      appendLog(['— L’anàlisi segueix en cua. Torna a aquesta pàgina d’aquí uns instants.']);
      setPct(100); finish(); return;
    }

    if (r.ok){
      if (typeof j.job==='string') jobUid=j.job;
      if (Number.isFinite(j.started_at_ms)) startClock(j.started_at_ms);
      if (j.poll){
        const pollUrl = j.poll.startsWith('http') ? j.poll : (new URL(j.poll, location.origin)).toString();
        saveCache({ job: jobUid||null, poll: pollUrl, started_at_ms: j.started_at_ms||null });
        setStage('Analitzant…'); poll(pollUrl); return;
      }
      setStage('En cua…'); appendLog(['— Job encolat. Obrint canal de seguiment…']);
      const found = await findPollTolerant();
      if (found && found.poll){
        const pollUrl = found.poll.startsWith('http') ? found.poll : (new URL(found.poll, location.origin)).toString();
        saveCache({ job: found.job||jobUid||null, poll: pollUrl, started_at_ms: found.started_at_ms||j.started_at_ms||null });
        if (Number.isFinite(found.started_at_ms)) startClock(found.started_at_ms);
        setStage('Analitzant…'); poll(pollUrl); return;
      }
      appendLog(['— L’anàlisi està en cua però el canal encara no és disponible.']); setPct(100); finish(); return;
    }

    setStage('Error iniciant l’anàlisi');
    appendLog(['ERROR: ' + (j.error || ('http_'+r.status))]);
    setPct(100); finish();
  }
})();
</script>