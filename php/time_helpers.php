<?php
// php/time_helpers.php — helpers d’hora/data unificats
declare(strict_types=1);

/**
 * Nota de disseny:
 * - A BD guardem timestamps en UTC.
 * - Per mostrar a UI, convertim a Europe/Madrid (o el TZ que calgui).
 * - Si ja s’ha fixat la TZ global al bootstrap, no la toquem aquí.
 */
if (!function_exists('date_default_timezone_get') || date_default_timezone_get() === '') {
  // Fallback molt conservador; normalment ja ho fa preload.php
  @date_default_timezone_set('Europe/Madrid');
}

/** Format europeu: dd/mm/aaaa HH:mm (accepta string/DateTime). */
if (!function_exists('dt_eu')) {
  /**
   * @param DateTimeInterface|string|null $dt
   * @param string $fmt  Format per defecte 'd/m/Y H:i'
   */
  function dt_eu($dt, string $fmt = 'd/m/Y H:i'): string {
    try {
      if ($dt instanceof DateTimeInterface) return $dt->format($fmt);
      if (is_string($dt) && $dt !== '') return (new DateTimeImmutable($dt))->format($fmt);
    } catch (Throwable $e) {}
    return '—';
  }
}

/**
 * Retorna una durada en format curt (sense el “fa”): 2 s, 3 min, 5 h, 7 d, 10 set, 2 m, 4 a
 * @param DateTimeInterface|string $dt  Moment passat respecte “ara”
 */
if (!function_exists('ago_short')) {
  function ago_short($dt): string {
    try {
      $now   = new DateTimeImmutable('now');
      $from  = $dt instanceof DateTimeInterface ? $dt : new DateTimeImmutable((string)$dt);
      $diff  = $now->getTimestamp() - $from->getTimestamp();
      if (!is_numeric($diff)) return '—';
      $s = max(0, (int)$diff);
      if ($s < 90)                 return $s . ' s';
      if ($s < 90 * 60)            return (int)round($s/60) . ' min';
      if ($s < 36 * 3600)          return (int)round($s/3600) . ' h';
      if ($s < 14 * 86400)         return (int)round($s/86400) . ' d';
      if ($s < 10 * 7 * 86400)     return (int)round($s/(7*86400)) . ' set';
      if ($s < 24 * 30 * 86400)    return (int)round($s/(30*86400)) . ' m';
      return (int)round($s/(365*86400)) . ' a';
    } catch (Throwable $e) { return '—'; }
  }
}

/**
 * Converteix una hora en UTC → Europe/Madrid (o TZ indicada).
 * Atenció: si passes un string sense TZ, s’interpretarà com UTC (assumpció del projecte).
 * @param DateTimeInterface|string $dt
 */
if (!function_exists('from_utc')) {
  function from_utc($dt, string $tz = 'Europe/Madrid'): ?DateTimeImmutable {
    try {
      $targetTz = new DateTimeZone($tz);
      if ($dt instanceof DateTimeInterface) {
        // Normalitza el DateTime existent a UTC abans de saltar a $tz
        return (new DateTimeImmutable($dt->format('Y-m-d H:i:s'), new DateTimeZone('UTC')))
               ->setTimezone($targetTz);
      }
      // Parseja el string assumint UTC
      return (new DateTimeImmutable((string)$dt, new DateTimeZone('UTC')))
             ->setTimezone($targetTz);
    } catch (Throwable $e) { return null; }
  }
}

/**
 * Converteix qualsevol DateTime a UTC (per guardar a BD).
 */
if (!function_exists('to_utc')) {
  function to_utc(DateTimeInterface $dt): DateTimeImmutable {
    $srcTz = $dt->getTimezone();
    return (new DateTimeImmutable($dt->format('Y-m-d H:i:s'), $srcTz ?: new DateTimeZone(date_default_timezone_get())))
           ->setTimezone(new DateTimeZone('UTC'));
  }
}

// ── Dates "zero-ish"
if (!function_exists('is_zeroish_date')) {
  function is_zeroish_date(?string $s): bool {
    if ($s === null) return true;
    $s = trim($s);
    if ($s === '' || $s === '0') return true;
    return in_array($s, [
      '0000-00-00', '0000-00-00 00:00:00', '0000-00-00T00:00:00', '1970-01-01 00:00:00'
    ], true);
  }
}

/**
 * safe_dt() — parseja un valor de data/hora de BD (UTC) i el retorna
 * com a DateTimeImmutable en Europe/Madrid (o TZ indicada).
 * Retorna null si el valor és buit o “zero-ish”.
 *
 * Accepta: string MYSQL/ISO o DateTimeInterface (assumit ja UTC).
 */
if (!function_exists('safe_dt')) {
  function safe_dt($dt, string $tz = 'Europe/Madrid'): ?DateTimeImmutable {
    try {
      if ($dt === null) return null;
      if ($dt instanceof DateTimeInterface) {
        // Assumeix que el DateTime és UTC i converteix a TZ
        return (new DateTimeImmutable($dt->format('Y-m-d H:i:s'), new DateTimeZone('UTC')))
               ->setTimezone(new DateTimeZone($tz));
      }
      $s = trim((string)$dt);
      if (is_zeroish_date($s)) return null;

      // Interpreta sempre strings de BD com UTC i després passa a TZ
      return (new DateTimeImmutable($s, new DateTimeZone('UTC')))
             ->setTimezone(new DateTimeZone($tz));
    } catch (Throwable $e) {
      return null;
    }
  }
}

/** Opcional: fem dt_eu una mica més resilient amb UTC→TZ */
if (!function_exists('dt_eu')) {
  function dt_eu($dt, string $fmt = 'd/m/Y H:i'): string {
    try {
      if ($dt instanceof DateTimeInterface) return $dt->format($fmt);
      if (is_string($dt) && !is_zeroish_date($dt)) {
        $loc = from_utc($dt); // interpreta string com UTC i passa a TZ per defecte
        if ($loc) return $loc->format($fmt);
      }
    } catch (Throwable $e) {}
    return '—';
  }
}