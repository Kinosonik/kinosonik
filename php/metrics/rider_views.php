<?php
// php/metrics/rider_views.php
declare(strict_types=1);

require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) { @session_start(); }

/**
 * Registra una vista d’un rider.
 * – No compta si el viewer és el propietari o és admin.
 * – Debounce anti-refresh (per rider) amb TTL curt (p.ex. 60s).
 * – Actualitza:
 *    • User_Recent_Riders (si loguejat i no propietari/admin)
 *    • Rider_View_Counters (totals; únics si nou)
 *    • (opcional) Rider_Views (event log)
 */
function record_rider_view(PDO $pdo, int $riderId, bool $logEvent = false): void {
  if ($riderId <= 0) return;

  // Debounce anti-refresh per sessió
  $_SESSION['_rv_last'] = $_SESSION['_rv_last'] ?? [];
  $now = time();
  $ttl = 60; // segons
  $last = (int)($_SESSION['_rv_last'][$riderId] ?? 0);
  if ($now - $last < $ttl) {
    // massa seguit; no incrementem
    return;
  }
  $_SESSION['_rv_last'][$riderId] = $now;

  // Dades de sessió
  $viewerId   = (int)($_SESSION['user_id'] ?? 0);
  $viewerRole = strtolower((string)($_SESSION['tipus_usuari'] ?? ''));

  // Propietari del rider i filtre propietari/admin
  $st = $pdo->prepare("SELECT ID_Usuari FROM Riders WHERE ID_Rider = ? LIMIT 1");
  $st->execute([$riderId]);
  $ownerId = (int)($st->fetchColumn() ?: 0);
  if ($ownerId <= 0) return; // rider no existeix (o esborrat)

  // No comptar propietari ni admins
  if ($viewerId > 0 && ($viewerId === $ownerId || $viewerRole === 'admin')) {
    return;
  }

  // Identificador de sessió “estable” per l’event log (no PII)
  if (empty($_SESSION['_rv_sid'])) {
    // Guarda una cadena estable en sessió (no binari); ja la convertirem més avall
    $_SESSION['_rv_sid'] = bin2hex(random_bytes(16));
  }

  try {
    $pdo->beginTransaction();

    $isLogged = ($viewerId > 0);
    error_log('VIEW-TEST viewerId=' . $viewerId . ' user=' . ($_SESSION['user_name'] ?? '?'));
    if ($isLogged) {
      // 1) User_Recent_Riders: insert o update
      $q = $pdo->prepare("
        INSERT INTO User_Recent_Riders (User_ID, ID_Rider, view_count, last_view_at)
        VALUES (:uid, :rid, 1, NOW())
        ON DUPLICATE KEY UPDATE
          view_count = view_count + 1,
          last_view_at = NOW()
      ");
      $q->execute([':uid' => $viewerId, ':rid' => $riderId]);
      // Affected rows: 1 (insert) o 2 (update)
      $isNewPair = ($q->rowCount() === 1);

      // 2) Counters globals
      if ($isNewPair) {
        // primer cop d’aquest usuari en aquest rider → únic +1
        $c = $pdo->prepare("
          INSERT INTO Rider_View_Counters (ID_Rider, total_views, unique_logged_users, anon_views, last_view_at)
          VALUES (:rid, 1, 1, 0, NOW())
          ON DUPLICATE KEY UPDATE
            total_views = total_views + 1,
            unique_logged_users = unique_logged_users + 1,
            last_view_at = NOW()
        ");
      } else {
        $c = $pdo->prepare("
          INSERT INTO Rider_View_Counters (ID_Rider, total_views, unique_logged_users, anon_views, last_view_at)
          VALUES (:rid, 1, 1, 0, NOW())
          ON DUPLICATE KEY UPDATE
            total_views = total_views + 1,
            last_view_at = NOW()
        ");
      }
      $c->execute([':rid' => $riderId]);

    } else {
      // Anònim → només counters globals
      $c = $pdo->prepare("
        INSERT INTO Rider_View_Counters (ID_Rider, total_views, unique_logged_users, anon_views, last_view_at)
        VALUES (:rid, 1, 0, 1, NOW())
        ON DUPLICATE KEY UPDATE
          total_views = total_views + 1,
          anon_views  = anon_views  + 1,
          last_view_at = NOW()
      ");
      $c->execute([':rid' => $riderId]);
    }

    // (Opcional) Event log
    if ($logEvent) {
      try {
        $ip   = $_SERVER['REMOTE_ADDR'] ?? null;
        $ipBin = $ip && filter_var($ip, FILTER_VALIDATE_IP) ? @inet_pton($ip) : null;
        $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $uaHash = $ua !== '' ? hash('sha256', $ua, true) : null;

        $sessionId = (string)($_SESSION['_rv_sid'] ?? '');
        // Construeix un hash binari consistent per emmagatzemar
        if (preg_match('/^[0-9a-f]{32,64}$/i', $sessionId)) {
          $sessionHash = @hex2bin($sessionId);
          if ($sessionHash === false) {
            $sessionHash = hash('sha256', $sessionId, true);
          }
        } else {
          $sessionHash = $sessionId !== '' ? hash('sha256', $sessionId, true) : null;
        }

        $viewerParam = ($viewerId > 0) ? $viewerId : null;

        $lv = $pdo->prepare("
          INSERT INTO Rider_Views (ID_Rider, Viewer_User_ID, session_hash, ip_bin, ua_hash, viewed_at)
          VALUES (:rid, :uid, :sh, :ip, :ua, NOW())
        ");
        $lv->execute([
          ':rid' => $riderId,
          ':uid' => $viewerParam,
          ':sh'  => $sessionHash,
          ':ip'  => $ipBin,
          ':ua'  => $uaHash,
        ]);
      } catch (Throwable $e) {
        error_log('record_rider_view (event log) error: ' . $e->getMessage());
        // no fem rollback del conjunt només per un problema d’event log
      }
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('record_rider_view error: ' . $e->getMessage());
  }
}