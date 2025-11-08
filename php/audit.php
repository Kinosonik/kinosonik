<?php
// php/audit.php
declare(strict_types=1);

if (!function_exists('audit_ip_bin')) {
  function audit_ip_bin(?string $ip): ?string {
    if ($ip === null || $ip === '') return null;
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
      $bin = @inet_pton($ip);
      return $bin === false ? null : $bin; // VARBINARY(16)
    }
    return null;
  }
}

if (!function_exists('client_ip_guess')) {
  function client_ip_guess(): ?string {
    // Si algun dia tens un reverse proxy fiable, adapta-ho aquí
    return $_SERVER['REMOTE_ADDR'] ?? null;
  }
}

if (!function_exists('audit_uuidv4')) {
  function audit_uuidv4(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
  }
}

if (!function_exists('audit_trunc')) {
  function audit_trunc(?string $s, int $max): ?string {
    if ($s === null) return null;
    return mb_substr($s, 0, $max);
  }
}

// Marca inici de petició per calcular latència
if (!defined('AUDIT_T0')) { define('AUDIT_T0', microtime(true)); }

if (!function_exists('audit_request_id')) {
  function audit_request_id(): string {
    static $rid = null;
    if ($rid) return $rid;
    $hdr = $_SERVER['HTTP_X_REQUEST_ID'] ?? $_SERVER['HTTP_CF_RAY'] ?? null;
    $rid = $hdr ? substr((string)$hdr, 0, 36) : audit_uuidv4();
    return $rid;
  }
}

if (!function_exists('audit_latency_ms')) {
  function audit_latency_ms(): int {
    return (int) round((microtime(true) - AUDIT_T0) * 1000);
  }
}

if (!function_exists('audit_country_guess')) {
  function audit_country_guess(): ?string {
    $cc = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? null;
    return (is_string($cc) && preg_match('/^[A-Z]{2}$/', $cc)) ? $cc : null;
  }
}

/**
 * Guarda una entrada d'auditoria. NO llença excepcions (swallow errors).
 *
 * Signatura compatible enrere. Els nous camps van a $opts:
 *   - request_id (char(36))
 *   - session_id (varchar(64))
 *   - route (varchar(128))
 *   - method ('GET','POST','PUT','PATCH','DELETE')
 *   - http_status (smallint unsigned)
 *   - target_type (varchar(32))  ex: 'rider','user','auth','file','system'
 *   - target_id (bigint)
 *   - target_uid (varchar(64))
 *   - actor_email (varchar(191))
 *   - impersonated_by (int)
 *   - latency_ms (int unsigned)
 *   - referer (varchar(255))
 *   - country (char(2))
 *
 * $meta: array → JSON (nullable)
 * $status: 'success' | 'error'
 */
if (!function_exists('audit_admin')) {
  function audit_admin(
    PDO $pdo,
    int $userId,
    bool $isAdmin,
    string $action,
    ?int $riderId,
    ?string $riderUid,
    ?string $context,                           // ← abans era string, ara nullable
    ?array $meta = null,
    string $status = 'success',
    ?string $errorMsg = null,
    array $opts = []                            // ← nous camps opcionals
  ): void {
    try {
      // Deriva valors per defecte
      $ipStr   = client_ip_guess();
      $ipBin   = audit_ip_bin($ipStr);
      $ua      = audit_trunc((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 255);
      $referer = audit_trunc((string)($_SERVER['HTTP_REFERER'] ?? ''), 255);
      $method  = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : null;
      $uri     = (string)($_SERVER['REQUEST_URI'] ?? '');
      $route   = $uri !== '' ? parse_url($uri, PHP_URL_PATH) : null;
      if (!is_string($route)) { $route = null; } // ← afegeix això
      $sessId  = session_id() ?: null;

      // Omple/normalitza opts
      $requestId = $opts['request_id'] ?? audit_request_id();


      $sessionId     = $opts['session_id']      ?? $sessId;
      $route         = $opts['route']           ?? $route;
      $method        = $opts['method']          ?? $method;

      $targetType    = $opts['target_type']     ?? null;
      $targetId      = isset($opts['target_id']) ? (int)$opts['target_id'] : null;
      $targetUid     = $opts['target_uid']      ?? null;

      $actorEmail = $opts['actor_email'] ?? (isset($_SESSION['email']) ? audit_trunc((string)$_SESSION['email'], 191) : null);
      $hs = http_response_code();
      $httpStatus = isset($opts['http_status']) ? (int)$opts['http_status'] : (is_int($hs) ? $hs : null);

      $impBy         = isset($opts['impersonated_by']) ? (int)$opts['impersonated_by'] : null;
      $latencyMs = isset($opts['latency_ms']) ? (int)$opts['latency_ms'] : audit_latency_ms();
      $country = $opts['country'] ?? audit_country_guess();
      // Seguretat/longituds
      $action     = audit_trunc($action, 64) ?? '';
      $context    = audit_trunc($context, 32);
      $targetType = audit_trunc($targetType, 32);
      $targetUid  = audit_trunc($targetUid, 64);
      $actorEmail = audit_trunc($actorEmail, 191);
      $route      = audit_trunc($route, 128);
      $method     = in_array($method, ['GET','POST','PUT','PATCH','DELETE'], true) ? $method : null;
      $status     = ($status === 'error') ? 'error' : 'success';
      $errorMsg   = audit_trunc($errorMsg, 255);

      $metaJson = (is_array($meta) && $meta !== [])
        ? json_encode($meta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
        : null;

      $sql = "
        INSERT INTO Admin_Audit
          (request_id, session_id, user_id, is_admin, actor_email, impersonated_by,
           action, target_type, target_id, target_uid, rider_id, rider_uid,
           route, method, http_status, latency_ms,
           ip, user_agent, referer, country,
           context, meta_json, status, error_msg)
        VALUES
          (:request_id, :session_id, :user_id, :is_admin, :actor_email, :imp_by,
           :action, :target_type, :target_id, :target_uid, :rider_id, :rider_uid,
           :route, :method, :http_status, :latency_ms,
           :ip, :ua, :referer, :country,
           :context, :meta_json, :status, :error_msg)
      ";

      $st = $pdo->prepare($sql);

      $st->bindValue(':request_id', $requestId);
      $st->bindValue(':session_id', $sessionId);
      $st->bindValue(':user_id', $userId, PDO::PARAM_INT);
      $st->bindValue(':is_admin', $isAdmin ? 1 : 0, PDO::PARAM_INT);
      $st->bindValue(':actor_email', $actorEmail);

      $st->bindValue(':action', $action);
      $st->bindValue(':target_type', $targetType);
      $st->bindValue(':target_id', $targetId, $targetId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
      $st->bindValue(':target_uid', $targetUid);

      $st->bindValue(':rider_id', $riderId, $riderId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
      $st->bindValue(':rider_uid', $riderUid);

      $st->bindValue(':route', $route);
      $st->bindValue(':method', $method);
      $st->bindValue(':http_status', $httpStatus, $httpStatus === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
      $st->bindValue(':latency_ms', $latencyMs, $latencyMs === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

      $st->bindValue(':ip', $ipBin, $ipBin === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
      $st->bindValue(':ua', $ua);
      $st->bindValue(':referer', $referer);
      $st->bindValue(':country', $country);

      $st->bindValue(':context', $context);
      $st->bindValue(':meta_json', $metaJson, $metaJson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      $st->bindValue(':status', $status);
      $st->bindValue(':error_msg', $errorMsg);

      $st->bindValue(':imp_by', $impBy, $impBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

      $st->execute();
    } catch (Throwable $e) {
      error_log('audit_admin error: ' . $e->getMessage());
    }
  }
}