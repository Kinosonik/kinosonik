<?php
// php/ai_utils.php — Helpers IA compartits
declare(strict_types=1);

if (!function_exists('detect_lang_heuristic')) {
  /**
   * Detecta l'idioma dominant del text d'un rider.
   * @param string $text Contingut del rider en text pla.
   * @return string Codi ISO d'idioma ('ca', 'es', 'en', 'fr', ...)
   */
  function detect_lang_heuristic(string $text): string {
    $t = strtolower(mb_substr($text, 0, 1000, 'UTF-8'));
    if (preg_match('/\b(el|la|los|una|un|de|que|para|por|con)\b/u', $t)) return 'es';
    if (preg_match('/\b(the|and|of|for|with|from)\b/u', $t)) return 'en';
    if (preg_match('/\b(el|la|els|les|per|amb|dels|una)\b/u', $t)) return 'ca';
    if (preg_match('/\b(le|la|les|des|pour|avec)\b/u', $t)) return 'fr';
    return 'und'; // undefined
  }
}

/**
 * Hi ha job actiu (queued/running) per a un rider?
 */
if (!function_exists('ai_has_active_job')) {
  function ai_has_active_job(PDO $pdo, int $riderId): bool {
    $sql = "SELECT COUNT(*) FROM ia_jobs WHERE rider_id = ? AND status IN ('queued','running')";
    $st  = $pdo->prepare($sql);
    $st->execute([$riderId]);
    return (int)$st->fetchColumn() > 0;
  }
}

/**
 * Darrera execució d’un rider (per started_at desc, fallback id desc).
 */
if (!function_exists('ai_last_run')) {
  function ai_last_run(PDO $pdo, int $riderId): ?array {
    $sql = "SELECT job_uid, status, score, stage, started_at
              FROM ia_runs
             WHERE rider_id = ?
             ORDER BY started_at DESC, id DESC
             LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$riderId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}

/**
 * Política per habilitar "Publica amb segell".
 * Requisits:
 *  - Valoracio > 80
 *  - Estat_Segell != 'validat' ni 'caducat'
 *  - Cap job actiu (queued/running)
 *  - Cap validació manual pendent (Riders.Validacio_Manual_Pendent = 0)
 *
 * Retorna: ['enabled'=>bool, 'reason'=>string]
 */
if (!function_exists('rider_can_auto_publish')) {
  function rider_can_auto_publish(PDO $pdo, array $rider): array {
    $reasons = [];

    // 1) Valoració
    $valoracio = isset($rider['Valoracio']) ? (int)$rider['Valoracio'] : null;
    if (!($valoracio !== null && $valoracio > 80)) {
      $reasons[] = 'Cal Puntuació > 80';
    }

    // 2) Estat segell
    $estat = strtolower(trim((string)($rider['Estat_Segell'] ?? '')));
    if ($estat === 'validat' || $estat === 'caducat') {
      $reasons[] = "Estat actual: $estat";
    }

    // 3) Job actiu
    // Accepta tant ID_Rider com ID per compatibilitat entre vistes
    $rid = (int)($rider['ID_Rider'] ?? $rider['ID'] ?? 0);
    if ($rid > 0 && ai_has_active_job($pdo, $rid)) {
      $reasons[] = 'Hi ha un job d’IA actiu';
    }

    // 4) Validació manual pendent
    $manual = (int)($rider['Validacio_Manual_Pendent'] ?? 0);
    if ($manual === 1) {
      $reasons[] = 'Hi ha una validació manual pendent';
    }

    return [
      'enabled' => empty($reasons),
      'reason'  => implode(' · ', $reasons),
    ];
  }
}