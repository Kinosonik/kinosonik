<?php
// act_upload_orig.php — puja el rider original d’una actuació
declare(strict_types=1);

require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/middleware.php';
require_once __DIR__ . '/php/ai_utils.php'; // enqueue_ia_job()
require_once __DIR__ . '/php/ks_pdf.php';   // ks_detect_seal()
require_once __DIR__ . '/php/r2.php';       // r2_upload()

ks_require_role('productor','admin');

// ─── Fallback: enqueue_ia_job() local si no ve d'ai_utils.php ────────────
// ─── Fallback robust: enqueue_ia_job amb introspecció d'esquema ─────────
// ─── enqueue_ia_job amb introspecció i suport act_id ─────────────────────
// ─── enqueue_ia_job amb introspecció + job_uid ───────────────────────────
if (!function_exists('enqueue_ia_job')) {
  function enqueue_ia_job(PDO $pdo, array $job): void {
    $src = (string)($job['source'] ?? 'producer_precheck');
    $sid = (int)($job['source_id'] ?? 0);
    $uid = (int)($job['user_id'] ?? 0);
    $aid = (int)($job['act_id'] ?? 0);
    $sha = isset($job['input_sha256']) ? (string)$job['input_sha256'] : '';

    // Columnes i metadades
    $cols = [];
    $meta = [];
    $stmt = $pdo->query("SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH
                         FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ia_jobs'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $cols[$r['COLUMN_NAME']] = true;
      if ($r['CHARACTER_MAXIMUM_LENGTH'] !== null) {
        $meta[$r['COLUMN_NAME']] = (int)$r['CHARACTER_MAXIMUM_LENGTH'];
      }
    }
    $has = fn(string $c) => isset($cols[$c]);

    // Generador de job_uid segons mida de columna
    // dins enqueue_ia_job(), just abans de construir :jid
    $mkJobUid = function(int $maxLen = 32): string {
      // 32–40 caràcters, suficientment únic
      $timeHex = dechex((int) (microtime(true) * 1_000_000));     // variable length
      $randHex = bin2hex(random_bytes(16));                        // 32 hex
      $s = $timeHex . $randHex;                                    // >32
      return substr($s, 0, max(8, $maxLen));
    };


    // ----- DEDUPE -----
    $dedupeSql = ''; $dedupeParams = [];
    if ($sha !== '' && $has('input_sha256')) {
      $dedupeSql = $has('status')
        ? "SELECT id FROM ia_jobs WHERE input_sha256 = :sha AND status IN ('queued','running') LIMIT 1"
        : "SELECT id FROM ia_jobs WHERE input_sha256 = :sha LIMIT 1";
      $dedupeParams = [':sha'=>$sha];
    } elseif ($aid && $has('act_id')) {
      $dedupeSql = $has('status')
        ? "SELECT id FROM ia_jobs WHERE act_id = :aid AND status IN ('queued','running') LIMIT 1"
        : "SELECT id FROM ia_jobs WHERE act_id = :aid LIMIT 1";
      $dedupeParams = [':aid'=>$aid];
    } elseif ($has('source') && $has('source_id')) {
      $dedupeSql = $has('status')
        ? "SELECT id FROM ia_jobs WHERE source = :src AND source_id = :sid AND status IN ('queued','running') LIMIT 1"
        : "SELECT id FROM ia_jobs WHERE source = :src AND source_id = :sid LIMIT 1";
      $dedupeParams = [':src'=>$src, ':sid'=>$sid];
    }
    if ($dedupeSql !== '') {
      $st = $pdo->prepare($dedupeSql);
      $st->execute($dedupeParams);
      if ($st->fetchColumn()) return;
    }

    // ----- INSERT dinàmic -----
    $insCols = []; $insVals = []; $params = [];

    // Referències
    if ($has('source'))    { $insCols[]='source';    $insVals[]=':src'; $params[':src']=$src; }
    if ($has('source_id')) { $insCols[]='source_id'; $insVals[]=':sid'; $params[':sid']=$sid; }
    if ($has('kind') && !isset($params[':src']))    { $insCols[]='kind';   $insVals[]=':src'; $params[':src']=$src; }
    if ($has('ref_id') && !isset($params[':sid']))  { $insCols[]='ref_id'; $insVals[]=':sid'; $params[':sid']=$sid; }

    if ($aid && $has('act_id')) { $insCols[]='act_id'; $insVals[]=':aid'; $params[':aid']=$aid; }
    if ($has('user_id'))        { $insCols[]='user_id'; $insVals[]=':uid'; $params[':uid']=$uid; }
    if ($sha !== '' && $has('input_sha256')) { $insCols[]='input_sha256'; $insVals[]=':sha'; $params[':sha']=$sha; }

    // job_uid si és obligatori
    if ($has('job_uid')) {
      $len = $meta['job_uid'] ?? 32;          // si no hi ha meta, 32
      $jid = $mkJobUid((int)$len);
      $insCols[]='job_uid'; 
      $insVals[]=':jid'; 
      $params[':jid']=$jid;
    }


    // Estat/temps
    if ($has('status'))    { $insCols[]='status';    $insVals[]="'queued'"; }
    if ($has('queued_at')) { $insCols[]='queued_at'; $insVals[]='NOW()'; }
    elseif ($has('ts'))    { $insCols[]='ts';        $insVals[]='NOW()'; }
    elseif ($has('created_at')) { $insCols[]='created_at'; $insVals[]='NOW()'; }

    if (!$insCols) { throw new RuntimeException('ia_jobs: esquema insuficient'); }

    $sql = "INSERT INTO ia_jobs (" . implode(',', $insCols) . ") VALUES (" . implode(',', $insVals) . ")";
    $ins = $pdo->prepare($sql);
    $ins->execute($params);
  }
}

$pdo     = db();
$uid     = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['tipus_usuari'] ?? '') === 'admin');
$actId   = (int)($_GET['id'] ?? 0);
if ($actId <= 0) { http_response_code(400); exit('bad_request'); }

// ─── Context + autorització ───────────────────────────────────────────────
$sqlCtx = <<<SQL
SELECT a.id AS act_id, a.stage_day_id, a.artista_nom,
       a.rider_orig_id, a.rider_orig_doc_id, a.rider_orig_sha256_last,
       d.dia, s.id AS stage_id, s.nom AS stage_nom,
       e.id AS event_id, e.nom AS event_nom, e.owner_user_id
FROM Stage_Day_Acts a
JOIN Stage_Days d   ON d.id = a.stage_day_id
JOIN Event_Stages s ON s.id = d.stage_id
JOIN Events e       ON e.id = s.event_id
WHERE a.id = :id
SQL;
$st = $pdo->prepare($sqlCtx);
$st->execute([':id'=>$actId]);
$ctx = $st->fetch(PDO::FETCH_ASSOC);
if (!$ctx) { http_response_code(404); exit('not_found'); }
if (!$isAdmin && (int)$ctx['owner_user_id'] !== $uid) { http_response_code(403); exit('forbidden'); }

// ─── Helpers ──────────────────────────────────────────────────────────────
function assert_pdf(string $path): void {
  $maxBytes = 25 * 1024 * 1024; // 25 MB
  if (!is_file($path) || filesize($path) === false) exit('file_missing');
  if (filesize($path) > $maxBytes) exit('file_too_big');

  $fh = @fopen($path, 'rb'); if (!$fh) exit('open_failed');
  $sig = fread($fh, 5); fclose($fh);
  if ($sig !== "%PDF-") exit('invalid_pdf_header');

  $fi = new finfo(FILEINFO_MIME_TYPE);
  $mime = $fi->file($path) ?: 'application/octet-stream';
  if (!in_array($mime, ['application/pdf','application/octet-stream'], true)) exit('invalid_mime');
}

function clean_filename(string $name): string {
  $name = preg_replace('/[^\w\-.]+/u', '_', $name) ?? 'document.pdf';
  return mb_substr($name, 0, 180);
}

// ─── POST ─────────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $csrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) { http_response_code(403); exit('csrf_invalid'); }

  if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) exit('upload_error');

  $tmpPath  = (string)$_FILES['file']['tmp_name'];
  $origName = clean_filename((string)$_FILES['file']['name']);
  $ext      = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));
  if ($ext !== 'pdf') exit('invalid_file_type');

  assert_pdf($tmpPath);

  $fi    = new finfo(FILEINFO_MIME_TYPE);
  $mime  = $fi->file($tmpPath) ?: 'application/pdf';
  $bytes = filesize($tmpPath);
  $sha   = hash_file('sha256', $tmpPath);
  $enqueuePayload = null;

  try {
    $pdo->beginTransaction();

    // Lock per coherència
    $pdo->prepare('SELECT id FROM Stage_Day_Acts WHERE id = :id FOR UPDATE')->execute([':id'=>$actId]);

    // Document antic vinculat (si n'hi ha)
    $oldDoc = null;
    if (!empty($ctx['rider_orig_doc_id'])) {
      $stOld = $pdo->prepare('SELECT id AS doc_id, r2_key, sha256 FROM Documents WHERE id = :id LIMIT 1');
      $stOld->execute([':id' => (int)$ctx['rider_orig_doc_id']]);
      $oldDoc = $stOld->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Si no hi ha canvis de contingut, sortir net
    if (!empty($ctx['rider_orig_sha256_last']) && $ctx['rider_orig_sha256_last'] === $sha) {
      $pdo->commit();
      header('Location: ' . BASE_PATH . 'actuacio.php?id=' . $actId . '&msg=unchanged');
      exit;
    }

    // ─── Detecció de segell Kinosonik ─────────────────────────────────────
    $seal = ks_detect_seal($tmpPath);

    // Preparar event
    $insEvt = $pdo->prepare('INSERT INTO Negotiation_Events
      (act_id, tipus, by_user, ts, doc_id, payload_json)
      VALUES (:act_id,:tipus,:by_user,NOW(),:doc_id,:payload)');

    if ($seal && !empty($seal['rider_id'])) {
      // CAS A: Rider validat Kinosonik → enllaça rider i neteja estat IA
      $pdo->prepare('UPDATE Stage_Day_Acts
                       SET rider_orig_id = :rid,
                           rider_orig_doc_id = NULL,
                           rider_orig_sha256_last = :sha,
                           ia_precheck_status = NULL,
                           ia_precheck_run_id = NULL,
                           ia_precheck_summary = NULL,
                           ia_precheck_score = NULL,
                           ia_precheck_ts = NULL
                     WHERE id = :id')
          ->execute([
            ':rid' => (int)$seal['rider_id'],
            ':sha' => $sha,
            ':id'  => $actId
          ]);

      $insEvt->execute([
        ':act_id'  => $actId,
        ':tipus'   => empty($ctx['rider_orig_doc_id']) && empty($ctx['rider_orig_id'])
                      ? 'rider_rebut' : 'rider_original_updated',
        ':by_user' => $uid,
        ':doc_id'  => null,
        ':payload' => json_encode([
          'sealed'      => true,
          'rider_id'    => (int)$seal['rider_id'],
          'sha256'      => $sha,
          'old_doc_id'  => (int)($oldDoc['doc_id'] ?? 0),
          'old_r2_key'  => (string)($oldDoc['r2_key'] ?? '')
        ], JSON_UNESCAPED_UNICODE)
      ]);

    } else {
      // CAS B: Rider manual → mira si ja tenim el mateix SHA
      $sel = $pdo->prepare('SELECT id, r2_key FROM Documents WHERE sha256 = :sha LIMIT 1');
      $sel->execute([':sha' => $sha]);
      $rowDoc = $sel->fetch(PDO::FETCH_ASSOC);

      if ($rowDoc) {
        $docId = (int)$rowDoc['id'];
        $r2Key = (string)$rowDoc['r2_key'];
      } else {
        $r2Key = sprintf('acts/%d/original_%s.pdf', $actId, bin2hex(random_bytes(6)));
        r2_upload($tmpPath, $r2Key, $mime);

        $insDoc = $pdo->prepare('INSERT INTO Documents
          (owner_user_id, kind, mime, bytes, sha256, r2_key, title, has_ks_seal, ks_seal_hash)
          VALUES (:owner_user_id, :kind, :mime, :bytes, :sha256, :r2_key, :title, :has_ks_seal, :ks_seal_hash)');
        $insDoc->execute([
          ':owner_user_id' => $uid,
          ':kind'          => 'band_original',
          ':mime'          => $mime,
          ':bytes'         => $bytes,
          ':sha256'        => $sha,
          ':r2_key'        => $r2Key,
          ':title'         => $origName,
          ':has_ks_seal'   => 0,
          ':ks_seal_hash'  => null
        ]);
        $docId = (int)$pdo->lastInsertId();
      }


      // Vincula a l’actuació i marca IA en cua
      $pdo->prepare("UPDATE Stage_Day_Acts
                  SET rider_orig_doc_id = :doc,
                      rider_orig_id = NULL,
                      rider_orig_sha256_last = :sha,
                      ia_precheck_status = 'queued',
                      ia_precheck_run_id = NULL,
                      ia_precheck_summary = NULL,
                      ia_precheck_score = NULL,
                      ia_precheck_ts = NOW()
                WHERE id = :id")
      ->execute([
        ':doc' => $docId,
        ':sha' => $sha,
        ':id'  => $actId
      ]);

      // Event línia de temps
      $insEvt->execute([
        ':act_id'  => $actId,
        ':tipus'   => empty($ctx['rider_orig_doc_id']) && empty($ctx['rider_orig_id'])
                      ? 'rider_rebut' : 'rider_original_updated',
        ':by_user' => $uid,
        ':doc_id'  => $docId,
        ':payload' => json_encode([
          'filename'   => $origName,
          'sha256'     => $sha,
          'mime'       => $mime,
          'bytes'      => $bytes,
          'new_doc_id' => (int)$docId,
          'old_doc_id' => (int)($oldDoc['doc_id'] ?? 0),
          'old_r2_key' => (string)($oldDoc['r2_key'] ?? '')
        ], JSON_UNESCAPED_UNICODE)
      ]);

      // Encola IA precheck amb dedupe per input_sha256
      $enqueuePayload = [
        'source'       => 'producer_precheck',
        'source_id'    => $actId,
        'act_id'       => $actId,
        'user_id'      => $uid,
        'input_sha256' => $sha,
      ];
    }

    $pdo->commit();

    // Encola IA post-commit per evitar curses amb el worker
    if ($enqueuePayload) {
      try { enqueue_ia_job($pdo, $enqueuePayload); }
      catch (Throwable $e) { error_log('enqueue_ia_job post-commit: '.$e->getMessage()); }
    }


    // ── Neteja: si hi havia document antic i ja no té referències, esborra’l
    try {
      if ($oldDoc) {
        // El nou doc pot no existir (cas segell)
        $newDocId = isset($docId) ? (int)$docId : 0;
        $oldId    = (int)$oldDoc['doc_id'];

        if ($oldId !== 0 && $oldId !== $newDocId) {
          // 1) Encara referenciat per alguna actuació?
          $refActs = (int)$pdo->query(
            "SELECT COUNT(*) FROM Stage_Day_Acts
            WHERE rider_orig_doc_id = {$oldId} OR final_doc_id = {$oldId}"
          )->fetchColumn();

          // 2) Encara referenciat a la línia de temps?
          $stRefEv = $pdo->prepare("SELECT COUNT(*) FROM Negotiation_Events WHERE doc_id = :d");
          $stRefEv->execute([':d' => $oldId]);
          $refEv = (int)$stRefEv->fetchColumn();

          if ($refActs === 0 && $refEv === 0) {
            // 3) Esborra a R2
            if (!empty($oldDoc['r2_key'])) {
              try {
                $c = r2_client(); $b = r2_bucket();
                $c->deleteObject(['Bucket' => $b, 'Key' => (string)$oldDoc['r2_key']]);
              } catch (Throwable $x) {
                error_log('R2 delete old doc: ' . $x->getMessage());
              }
            }
            // 4) Esborra fila Documents
            $pdo->prepare('DELETE FROM Documents WHERE id = :id')->execute([':id' => $oldId]);
          }
        }
      }
    } catch (Throwable $x) {
      error_log('CLEANUP old doc failed: ' . $x->getMessage());
    }

    header('Location: ' . BASE_PATH . 'actuacio.php?id=' . $actId);
    exit;


  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("ACT_UPLOAD_ERR: ".$e->getMessage());
    http_response_code(500);
    exit('db_error');
  }
}

/* ─── FORM ──────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';
?>
<div class="container w-50">
  <div class="card k-card">
    <div class="card-header"><i class="bi bi-upload me-1"></i> Pujar rider original</div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
        <div class="mb-3">
          <label for="file" class="form-label">Fitxer PDF</label>
          <input type="file" class="form-control" id="file" name="file" accept="application/pdf" required>
          <div class="form-text">PDF, màx. 25 MB.</div>
        </div>
        <div class="text-end">
          <a href="<?= h(BASE_PATH) ?>actuacio.php?id=<?= (int)$actId ?>" class="btn btn-secondary btn-sm">Cancel·la</a>
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-upload"></i> Pujar
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/parts/footer.php'; ?>
