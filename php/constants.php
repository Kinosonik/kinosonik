<?php
declare(strict_types=1);

// ── SEAL STATES (DB ENUM) ─────────────────────────────────────────────
// Valors a la taula: cap / pendent / validat / caducat
const SEAL_CAP      = 'cap';
const SEAL_PENDENT  = 'pendent';
const SEAL_VALIDAT  = 'validat';
const SEAL_CADUCAT  = 'caducat';

const SEAL_ALLOWED = [SEAL_CAP, SEAL_PENDENT, SEAL_VALIDAT, SEAL_CADUCAT];

/**
 * Normalitza l’estat a un dels permesos. Si és desconegut → 'cap'.
 */
function seal_normalize(?string $s): string {
  $e = strtolower(trim((string)$s));
  return in_array($e, SEAL_ALLOWED, true) ? $e : SEAL_CAP;
}

function seal_is_validat(?string $s): bool { return seal_normalize($s) === SEAL_VALIDAT; }
function seal_is_caducat(?string $s): bool { return seal_normalize($s) === SEAL_CADUCAT; }
function seal_is_pendent(?string $s): bool { return seal_normalize($s) === SEAL_PENDENT; }

/** Estat final (no editable per l’usuari): validat o caducat */
function seal_is_final(?string $s): bool {
  $e = seal_normalize($s);
  return $e === SEAL_VALIDAT || $e === SEAL_CADUCAT;
}

/** Editable per l’usuari (no final): cap o pendent */
function seal_is_editable(?string $s): bool {
  $e = seal_normalize($s);
  return $e === SEAL_CAP || $e === SEAL_PENDENT;
}

/**
 * Política actual d’auto-publicació:
 *  - score >= $minScore
 *  - sense validació manual pendent ($manualReq=false/0)
 *  - estat NO final
 */
function seal_can_auto_publish(string $estat, ?int $score, bool $manualReq, int $minScore = 81): bool {
  if ($score === null || $score < $minScore) return false;
  if ($manualReq) return false;
  return !seal_is_final($estat);
}

/**
 * Ordre consistent per mostrar o comparar estats.
 * cap(0) < pendent(1) < validat(2) < caducat(3)
 */
function seal_order(string $estat): int {
  return match (seal_normalize($estat)) {
    SEAL_CAP     => 0,
    SEAL_PENDENT => 1,
    SEAL_VALIDAT => 2,
    SEAL_CADUCAT => 3,
    default      => 0,
  };
}

/** Comparador per a usort(): retorna -1,0,1 */
function seal_compare(string $a, string $b): int {
  return seal_order($a) <=> seal_order($b);
}

/**
 * Icona (Bootstrap Icons), classe de color i títol localitzat.
 * No obliga a carregar i18n: si existeix __() l’usa, si no, cadenes fixes.
 */
function seal_icon_title(string $estat): array {
  $e = seal_normalize($estat);

  $t = function(string $key, string $fallback): string {
    return (function_exists('__') && is_string(($v = __($key))) && $v !== $key && $v !== '')
      ? $v : $fallback;
  };

  return match ($e) {
    SEAL_VALIDAT => ['bi-shield-fill-check', 'text-success',  $t('seal.validat', 'Rider validat')],
    SEAL_CADUCAT => ['bi-shield-fill-x',     'text-danger',   $t('seal.caducat', 'Rider caducat')],
    SEAL_PENDENT => ['bi-shield-exclamation','text-warning',  $t('seal.pendent', 'Rider pendent')],
    default      => ['bi-shield',            'text-secondary',$t('seal.cap',     'Sense segell')],
  };
}
/* ---------- Constants riders publicats - */
// Quins estats són visibles per al PÚBLIC (no propietari/admin)
const PUBLIC_SEAL_VISIBLE = ['validat']; 
// Si vols també “caducat”:
//// const PUBLIC_SEAL_VISIBLE = ['validat','caducat'];

function seal_is_public_visible(?string $s): bool {
  return in_array(strtolower(trim((string)$s)), PUBLIC_SEAL_VISIBLE, true);
}

function can_view_rider(?string $seal, bool $isOwner, bool $isAdmin): bool {
  if ($isAdmin || $isOwner) return true;
  return seal_is_public_visible($seal);
}
