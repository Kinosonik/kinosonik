<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
$home = origin_url() . BASE_PATH;
// Si vols forçar WWW-Authenticate per a zones realment protegides tipus "Basic", el podries afegir:
// header('WWW-Authenticate: Bearer realm="Kinosonik Riders"'); // exemple
http_response_code(401);
?>
<!doctype html>
<html lang="ca">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>401 — No autenticat · Kinosonik Riders</title>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    :root{color-scheme:light dark}
    *{box-sizing:border-box}html,body{height:100%}
    body{margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,'Helvetica Neue',Arial,'Noto Sans',sans-serif;
         display:grid;place-items:center;background:#0b0f14;color:#e6eef7}
    .card{max-width:680px;width:92%;background:linear-gradient(180deg,#0f1621,#0b0f14);
          border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:28px 26px;box-shadow:0 10px 30px rgba(0,0,0,.35)}
    .code{display:inline-block;font-weight:700;font-size:13px;padding:.2rem .55rem;border-radius:999px;
          color:#ffd8a8;background:rgba(255,193,7,.12);border:1px solid rgba(255,193,7,.25);letter-spacing:.06em}
    h1{margin:.6rem 0 0;font-size:clamp(28px,4vw,34px);line-height:1.15}
    p{margin:.6rem 0 0;color:#b8c7d9}
    .hint{margin-top:14px;font-size:14px;color:#9fb0c4}
    .actions{display:flex;gap:10px;margin-top:18px;flex-wrap:wrap}
    a.btn{appearance:none;text-decoration:none;color:#0b1220;background:#c2e7ff;border:1px solid #a7d5f7;
          padding:.65rem .9rem;border-radius:10px;font-weight:600}
    a.ghost{background:transparent;color:#cfe2ff;border-color:rgba(255,255,255,.2)}
  </style>
</head>
<body>
  <main class="card">
    <span class="code">401 · No autenticat</span>
    <h1>Cal iniciar sessió per continuar.</h1>
    <p>No hem detectat cap sessió activa. Accedeix amb el teu compte per veure aquesta pàgina.</p>
    <p class="hint">Si has tancat sessió fa poc, torna a iniciar-la i reintenta-ho.</p>
    <div class="actions">
      <a class="btn" href="<?= h($home) ?>">Anar a l’inici</a>
      <a class="btn ghost" href="javascript:history.back()">← Tornar enrere</a>
    </div>
  </main>
</body>
</html>