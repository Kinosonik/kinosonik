<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/preload.php';

// Llegeix secrets (array)
$secrets   = require dirname(__DIR__) . '/secret.php';
$authKey   = $secrets['KS_LOG_VIEW_KEY'] ?? '';
$basePath  = $secrets['BASE_PATH'] ?? '/riders/';
if ($authKey === '') {
  header('Content-Type: text/plain; charset=UTF-8');
  echo "ERROR: KS_LOG_VIEW_KEY no definit a php/secret.php";
  exit;
}

// IMPORTANT: com estàs a /php/tools, aquesta URL és la bona
$streamUrl    = rtrim($basePath, '/') . '/php/tools/log_stream.php?k=' . urlencode($authKey);
$logPathShown = '/var/config/logs/riders/php-error.log';
?>
<!doctype html>
<html lang="ca">
<head>
  <meta charset="utf-8">
  <title>Visor Log Kinosonik (live)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root { color-scheme: dark; }
    * { box-sizing: border-box; }
    body { margin:0; font:14px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background:#0b0c10; color:#d7d7d7; }
    header { display:flex; gap:.75rem; align-items:center; padding:.75rem 1rem; background:#111319; position:sticky; top:0; z-index:1; border-bottom:1px solid #222; flex-wrap:wrap; }
    header h1 { font-size:1rem; margin:0; color:#cdd6f4; font-weight:600; }
    .pill { padding:.3rem .6rem; border:1px solid #2a2f3a; border-radius:.6rem; background:#141722; }
    .toolbar { display:flex; gap:.5rem; align-items:center; margin-left:auto; flex-wrap:wrap; }
    label, select, input, button { font-size:.9rem; }
    .controls { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
    .search { padding:.35rem .5rem; border:1px solid #2a2f3a; background:#0f1118; color:#d7d7d7; border-radius:.4rem; min-width:160px; }
    .btn { padding:.35rem .65rem; border:1px solid #2a2f3a; background:#141722; color:#d7d7d7; border-radius:.4rem; cursor:pointer; }
    .btn:hover { background:#1a1f2b; }
    #status { font-size:.8rem; opacity:.8; }
    #url { font-size:.78rem; opacity:.7; }
    #log { white-space:pre-wrap; word-break:break-word; padding:10px 12px; line-height:1.35; }
    .line { margin:0; }
    .muted { opacity:.55; }
    .tag { display:inline-block; padding:.05rem .35rem; border-radius:.35rem; margin-right:.4rem; font-size:.75rem; border:1px solid #2a2f3a; }
    .APP   { color:#a6e3a1; border-color:#214a2e; }
    .PHP   { color:#89b4fa; border-color:#1f3b66; }
    .EXC   { color:#f9e2af; border-color:#5b4a1a; }
    .FATAL { color:#f38ba8; border-color:#5c1e28; }
    .footer { padding:6px 12px; border-top:1px solid #222; font-size:.8rem; opacity:.75; }
    /* Toggle suavitzat del context */
    body[data-muted="0"] .muted { opacity: 1; }
  </style>
</head>
<body>
<header>
  <h1>Visor Log Kinosonik (live)</h1>
  <div class="pill" id="status">connectant…</div>
  <div class="pill" id="url">stream: <?php echo htmlspecialchars($streamUrl, ENT_QUOTES); ?></div>

  <div class="toolbar">
    <div class="controls pill">
      <label for="level">Nivell:</label>
      <select id="level">
        <option value="ALL">Tots</option>
        <option value="APP">APP</option>
        <option value="PHP">PHP</option>
        <option value="EXC">EXC</option>
        <option value="FATAL">FATAL</option>
      </select>
    </div>

    <div class="controls pill">
      <label><input type="checkbox" id="follow" checked> Segueix</label>
      <label><input type="checkbox" id="muted" checked> Suavitza context</label>
      <label><input type="checkbox" id="pause"> Pausa</label>
    </div>

    <!-- 🔎 Filtres: global + específics -->
    <div class="controls pill">
      <input id="q"   class="search" placeholder="Filtra (regex o text)…">
      <input id="rid" class="search" placeholder="RID…">
      <input id="ip"  class="search" placeholder="IP…">
      <input id="uri" class="search" placeholder="URI…">
      <input id="sid" class="search" placeholder="SID…">
      <button id="clear" class="btn">Neteja</button>
      <button id="copy" class="btn">Copia</button>
      <button id="clearFile" class="btn">Buida fitxer</button>
    </div>
  </div>
</header>

<main id="log"></main>
<div class="footer">Fitxer: <?php echo htmlspecialchars($logPathShown, ENT_QUOTES); ?></div>

<script>
(() => {
  const logEl = document.getElementById('log');
  const levelSel = document.getElementById('level');
  const followChk = document.getElementById('follow');
  const mutedChk  = document.getElementById('muted');
  const pauseChk  = document.getElementById('pause');
  const qInput    = document.getElementById('q');
  const ridInput  = document.getElementById('rid');
  const ipInput   = document.getElementById('ip');
  const uriInput  = document.getElementById('uri');
  const sidInput  = document.getElementById('sid');
  const statusEl  = document.getElementById('status');
  const clearBtn  = document.getElementById('clear');
  const copyBtn   = document.getElementById('copy');
  const clearFileBtn = document.getElementById('clearFile');

  let filterRegex = null;
  let es = null;

  const STREAM_URL = "<?php echo htmlspecialchars($streamUrl, ENT_QUOTES); ?>";

  function setStatus(s) { statusEl.textContent = s; }

  function parseLevel(line) {
    if (line.includes(' [APP] ')) return 'APP';
    if (line.includes(' [PHP ')) return 'PHP';
    if (line.includes(' [EXC ')) return 'EXC';
    if (line.includes(' [FATAL ')) return 'FATAL';
    return 'APP';
  }

  function formatLine(line) {
    const level = parseLevel(line);
    const div = document.createElement('div');
    div.className = 'line';
    // marca context [RID][IP][URI][SID] com “muted”
    let html = line
      .replace(/^\s+|\s+$/g,'')
      .replace(/^(\[[^\]]+\]\s*)+/g, (m) => `<span class="muted">${m}</span>`);
    html = `<span class="tag ${level}">${level}</span> ` + html;
    div.innerHTML = html;
    return div;
  }

  function matchFilters(line) {
    // nivell
    const level = levelSel.value;
    if (level !== 'ALL' && parseLevel(line) !== level) return false;

    // filtre global (regex/text)
    if (filterRegex) {
      try { if (!filterRegex.test(line)) return false; } catch {}
    }

    // filtres específics (conté text literal)
    if (ridInput.value && !line.includes(ridInput.value)) return false;
    if (ipInput.value  && !line.includes(ipInput.value))  return false;
    if (uriInput.value && !line.includes(uriInput.value)) return false;
    if (sidInput.value && !line.includes(sidInput.value)) return false;

    return true;
  }

  function appendLine(line) {
    if (pauseChk.checked) return;
    if (!matchFilters(line)) return;
    const node = formatLine(line);
    logEl.appendChild(node);
    if (followChk.checked) node.scrollIntoView({behavior:'instant', block:'end'});
    if (logEl.childElementCount > 2000) logEl.removeChild(logEl.firstChild);
  }

  function connect() {
    if (es) { es.close(); es = null; }
    setStatus('connectant…');
    es = new EventSource(STREAM_URL);
    es.onopen = () => setStatus('en línia');
    es.onmessage = (e) => {
      if (!e.data) return;
      // pot venir amb múltiples línies en un sol event
      e.data.split('\n').forEach(l => { if (l.trim() !== '') appendLine(l); });
    };
    es.onerror = () => {
      setStatus('reconnectant…');
      // EventSource reintenta automàticament
    };
  }

  // --- wiring dels filtres ---
  function rebuildRegexFromQ() {
    const v = qInput.value.trim();
    filterRegex = null;
    if (v) {
      try { filterRegex = new RegExp(v, 'i'); }
      catch { filterRegex = new RegExp(v.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i'); }
    }
  }
  [qInput, ridInput, ipInput, uriInput, sidInput].forEach(el => {
    el.addEventListener('input', rebuildRegexFromQ);
  });

  // Controls varis
  mutedChk.addEventListener('change', () => {
    document.body.dataset.muted = mutedChk.checked ? '1' : '0';
  });
  document.body.dataset.muted = '1'; // per defecte activat

  clearBtn.addEventListener('click', () => { logEl.textContent = ''; });

  copyBtn.addEventListener('click', async () => {
    let text = '';
    logEl.querySelectorAll('.line').forEach(n => { text += n.textContent + '\n'; });
    try { await navigator.clipboard.writeText(text); setStatus('copiat al portaretalls'); }
    catch { setStatus('no s’ha pogut copiar'); }
    setTimeout(() => setStatus('en línia'), 1500);
  });

  // Buida fitxer (POST) amb cache-buster
  clearFileBtn.addEventListener('click', async () => {
    if (!confirm('Segur que vols buidar completament el fitxer de log?')) return;
    setStatus('buidant log…');
    try {
      const resp = await fetch('<?php echo rtrim($basePath, "/"); ?>/php/tools/log_clear.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'k=<?php echo urlencode($authKey); ?>&t=' + Date.now()
      });
      const txt = await resp.text();
      if (resp.ok && txt.trim() === 'OK') {
        logEl.textContent = '';
        setStatus('log buidat');
      } else {
        setStatus('error: ' + txt);
      }
    } catch (err) {
      console.error(err);
      setStatus('error de connexió');
    }
    setTimeout(() => setStatus('en línia'), 3000);
  });

  // “tic” visual del client quan connectat
  setInterval(() => {
  if (statusEl.textContent.includes('en línia') && !pauseChk.checked) {
    appendLine('[client] heartbeat ' + new Date().toLocaleTimeString());
  }
}, 60000);

  connect();

  // Tanca la connexió SSE quan tanques/recarregues
  window.addEventListener('beforeunload', () => { if (es) es.close(); });
})();
</script>
</body>
</html>