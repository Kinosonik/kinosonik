<?php
// final_upload.php — Pujar document FINAL (crea Documents + enllaça i opcionalment marca estat)
declare(strict_types=1);

require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/middleware.php';

ks_require_role('productor','admin');

if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
}
function guess_upload_dir(): string {
  $candidates = [
    $_ENV['KS_SECURE_UPLOAD_DIR'] ?? '',
    '/var/config/uploads/riders_docs',
    dirname(__DIR__) . '/secure/uploads',
  ];
  foreach ($candidates as $p) {
    if ($p && @is_dir($p) && @is_writable($p)) return rtrim($p,'/');
  }
  return sys_get_temp_dir();
}

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');
$actId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($actId<=0){ http_response_code(400); exit('bad_request'); }

$sql = <<<SQL
SELECT a.id, e.owner_user_id
FROM Stage_Day_Acts a
JOIN Stage_Days d   ON d.id=a.stage_day_id
JOIN Event_Stages s ON s.id=d.stage_id
JOIN Events e       ON e.id=s.event_id
WHERE a.id=:id
SQL;
$st = $pdo->prepare($sql); $st->execute([':id'=>$actId]);
$ctx = $st->fetch(PDO::FETCH_ASSOC);
if(!$ctx){ http_response_code(404); exit('not_found'); }
if(!$isAdmin && (int)$ctx['owner_user_id'] !== $uid){ http_response_code(403); exit('forbidden'); }

$err='';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $csrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) { http_response_code(403); exit('csrf_invalid'); }

  if (!isset($_FILES['pdf']) || ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $err = 'Puja un fitxer PDF.';
  } else {
    $f = $_FILES['pdf'];
    $data = file_get_contents($f['tmp_name']);
    if ($data===false) { $err='No s’ha pogut llegir el fitxer.'; }
    else {
      $sha = hash('sha256', $data);
      $dir = guess_upload_dir();
      $name = (string)$f['name'];
      $safe = preg_replace('/[^A-Za-z0-9._-]+/','_',$name) ?: ('final_'.$sha.'.pdf');
      $dest = $dir . '/final_' . $sha . '.pdf';
      if (!@move_uploaded_file($f['tmp_name'], $dest)) {
        if (@file_put_contents($dest, $data) === false) {
          $err = 'Error escrivint al disc.';
        }
      }
      if ($err==='') {
        try {
          $cols = $pdo->query("SHOW COLUMNS FROM Documents")->fetchAll(PDO::FETCH_COLUMN,0);
          $has = fn(string $c)=>in_array($c,$cols,true);

          $params=[]; $names=[];
          if ($has('owner_user_id')) { $names[]='owner_user_id'; $params[':owner_user_id']=$uid; }
          if ($has('filename'))      { $names[]='filename';      $params[':filename']=$safe; }
          if ($has('mime'))          { $names[]='mime';          $params[':mime']='application/pdf'; }
          elseif ($has('mime_type')) { $names[]='mime_type';     $params[':mime']='application/pdf'; }
          if ($has('size_bytes'))    { $names[]='size_bytes';    $params[':bytes']=(int)filesize($dest); }
          elseif ($has('bytes'))     { $names[]='bytes';         $params[':bytes']=(int)filesize($dest); }
          if ($has('sha256'))        { $names[]='sha256';        $params[':sha']=$sha; }
          if ($has('storage_path'))  { $names[]='storage_path';  $params[':path']=$dest; }
          elseif ($has('local_path')){ $names[]='local_path';    $params[':path']=$dest; }
          if ($has('storage'))       { $names[]='storage';       $params[':storage']='local'; }
          if ($has('ts_created'))    { $names[]='ts_created';    $params[':ts']=date('Y-m-d H:i:s'); }

          $sql = 'INSERT INTO Documents ('.implode(',',$names).') VALUES ('.implode(',',array_keys($params)).')';
          $pdo->prepare($sql)->execute($params);
          $docId = (int)$pdo->lastInsertId();

          // Enllaça com a FINAL i, si vols, marca estat final_publicat
          $pdo->prepare('UPDATE Stage_Day_Acts SET final_doc_id=:d, negotiation_state=IF(negotiation_state<>"final_publicat","final_publicat",negotiation_state), ts_updated=NOW() WHERE id=:id')
              ->execute([':d'=>$docId, ':id'=>$actId]);

          header('Location: ' . (h(BASE_PATH).'actuacio.php?id='.$actId));
          exit;
        } catch (Throwable $e) {
          $err='Error al desar a BD (Documents). Revisa l’esquema.';
        }
      }
    }
  }
}

require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';
?>
<div class="container w-75">
  <div class="d-flex justify-content-between align-items-center mb-2 border-bottom border-1 border-secondary">
    <h4 class="text-start"><i class="bi bi-upload"></i>&nbsp;Pujar FINAL</h4>
    <div class="btn-group d-flex">
      <a class="btn btn-primary btn-sm" href="<?= h(BASE_PATH) ?>actuacio.php?id=<?= (int)$actId ?>">
        <i class="bi bi-arrow-left"></i>
      </a>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-warning k-card"><?= h($err) ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" action="<?= h(BASE_PATH) ?>final_upload.php">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
    <input type="hidden" name="id" value="<?= (int)$actId ?>">
    <div class="mb-3">
      <label class="form-label">PDF final</label>
      <input type="file" name="pdf" accept="application/pdf" required class="form-control">
      <div class="form-text">Només PDF.</div>
    </div>
    <div class="text-end">
      <button class="btn btn-primary"><i class="bi bi-check2"></i> Desar</button>
    </div>
  </form>
</div>
<?php require_once __DIR__ . '/parts/footer.php'; ?>
