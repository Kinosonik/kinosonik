<?php
// php/ia_extract_heuristics.php
declare(strict_types=1);

/* ───────── Classificador ràpid: és un rider? (0..100) ───────── */
function rider_confidence(string $norm): int {
  $score = 0;
  // Senyals positives "de rider"
  $hasRiderWord = preg_match('~technical\s*\R?\s*rider|rider\s*t[èe]cnic|rider|tech\s*specs?|technical\s*specifications?~iu', $norm);
  $hasStagePlot = (bool)preg_match('~\b(stage\s*(plot|plan)|stageplan|escenari|escenario|plano\s*escenario|stage\s*layout)\b~iu', $norm);
  $hasPatchWord = (bool)preg_match('~\b(patch|input\s*(list|sheet)|canals?|channels?|d\.?i\.?|mic|micro(?:phone)?s?)\b~iu', $norm);

  // Línies de patch "de veritat": començament de línia amb CH/IN/CANAL + número + separador
  // Més tolerant: permet "1 kick" (un sol espai després del número)
  $chanLines = (int)preg_match_all(
    '~(^|\n)\s*(?:ch(?:an(?:nel)?)?|canal|in(?:put)?|channel\s*list)?\s*\d{1,3}\s*(?:[:\.\-\)]|\||·|•|\t|\s+)\s*~imu',
    $norm
  );

  // Senyals d’àudio
  $hasAudioLex = (bool)preg_match('~\b(mic|micro(?:phone)?s?|di|xlr|trs|phantom|48\s*v|sm57|sm58|d112|beta\s?52|e906|iem|in-?ears?|pa|foh|monitor(?:s)?|amplificador|cabinet|mesa\s*de\s*mezclas?)\b~iu', $norm);
  // DI/XLR explícit per al boost de rider_confidence
  $hasDIorXLR  = (bool)preg_match('~\b(di|direct\s*box|line\s*box|xlr|balanced)\b~iu', $norm);
  $gearLex     = (bool)preg_match('~\b(backline|equipment\s*list|amplifier|cabinet|mixer|console|pa\s*system|monitor(?:s)?|power\s*supply)\b~iu', $norm);
  $hasContactLex = (bool)preg_match('~\b(contact|contacte|email|e-?mail|mail|tel(?:èfon|éfono)?|phone)\b~iu', $norm);
  $hasReqLex     = (bool)preg_match('~\b(necessitats|needs|requisitos|technical\s*requirements?|power|corr?ent|electricitat|hospitality|stage\s*(plot|plan)|input\s*list|patch)\b~iu', $norm);
  $instrHits = preg_match_all('~\b(kick|bombo|snare|caixa|tom|overheads?|oh|hihat|charles|guitarra|guitar|baix|bass|keys?|piano|voz|veu|vocal|cymbals?|plats?)\b~iu', $norm);
  $micHits   = preg_match_all('~\b(sm57|sm58|beta\s?58|e9(?:06|09|35|45)|e604|e904|e935|e945|e965|md421|km184|re20|c414|sm7b|i5|m88|m201|d6|d4|d2|kms\s?105|ksm9|beta\s?87a?|om7|om5|dpa\s?4099)\b~iu', $norm);

  // Boost suau per densitat d’instruments + micros coneguts (riders minimalistes “reals”)
  if ($instrHits >= 6 && $micHits >= 2) {
     $score += 12;
  }
  // Senyals de MANUAL
  $manualish = is_manual_like($norm);

  // Puntuació base (molt conservadora)
  if ($hasRiderWord) $score += 35;
  if ($hasStagePlot) $score += 25;
  if ($hasPatchWord) $score += 20;

  // Bonus per lèxic típic de “backline / FOH / PA / gear list”
  if ($hasAudioLex) $score += 15;
  if ($gearLex) {
    $score += 10;
  }

  // ── Penalització lleu: “gear list only” (sense contacte ni requisits)
  //   Casos com “Tech Specs / Equipment List” que no indiquen ni patch, ni stage plot,
  //   ni dades de contacte ni necessitats → és un rider molt minimalista.
  $gearListOnly = ($gearLex || $hasAudioLex)
                  && !$hasPatchWord && !$hasStagePlot
                  && !$hasContactLex && !$hasReqLex;

  if ($gearListOnly) {
  $score -= 10;

  if ($instrHits >= 6 && $micHits >= 2) {
    $score += 25;
    $score = max($score, 68);   // abans 62
    $score = min($score, 75);   // abans 70
  } elseif ($instrHits >= 6 || $micHits >= 2) {
    $score += 15;
    $score = min($score, 68);   // abans 65
  } else {
    $score = min($score, 55);
  }
  $score = max(0, $score);
  }

  // Evidència de patch real
  if ($chanLines >= 2 && $hasAudioLex) $score = max($score, 62);          // passa el tall de 'rider'
  elseif ($chanLines === 1 && $hasAudioLex) $score += 10;

  // BOOST nou: ≥4 línies "canal-like" + DI/XLR → puja sòl +6 i assegura 62
  // (això captura llistes tipus "1 kick di", "2 bass di", "3 ... xlr (balanced)")
  if ($chanLines >= 4 && $hasDIorXLR) {
    $score = max($score, 62);
    $score += 6; // boost suau però consistent
  }

  // Anti genèrics d’administració
  if (preg_match('~\b(invoice|factura|contract|contrato|press\s*release|nota\s*de\s*premsa)\b~iu', $norm)) {
    $score -= 20;
  }

  // Clamp
  $score = max(0, min(100, $score));

  // HARD GATES:
  //  - Si és manual i no hi ha stage plot ni ≥2 línies de patch → baixa a zona "not rider"
  if ($manualish && !($hasStagePlot || ($chanLines >= 2 && $hasPatchWord))) {
  $audioish = ($instrHits >= 4) && ($micHits >= 1 || $hasAudioLex);
  if ($audioish) {
    // deixa que el rc pugui superar 60 si hi ha lèxic clar
    $score = min($score, 68);
  } else {
    $score = min($score, 30);
  }
  }
  // ── Debug opcional (registre discret a log) ───────────────────────────────
  $logPath = '/var/config/logs/riders/ia_debug.log';
  $dbgStrong = preg_match_all('~\b(channel|patch|stage|input)\b~iu', $norm);
  $dbgWeak   = preg_match_all('~\b(backline|drums?|amp|monitor)\b~iu', $norm);
  $dbgAnti   = preg_match_all('~\b(invoice|manual|software|contract)\b~iu', $norm);
  $dbgManual = preg_match('~\b(manual|user\s*guide|software|installation|setup)\b~iu', $norm);
  $info = sprintf("[%s] rider_confidence=%d strong=%d weak=%d anti=%d manual=%d instr=%d mic=%d\n",
  date('Y-m-d H:i:s'), $score, $dbgStrong, $dbgWeak, $dbgAnti, $dbgManual ? 1 : 0, $instrHits, $micHits);
  if ($gearListOnly) { $info = rtrim($info) . " gearListOnly=1\n"; }
  
  @file_put_contents($logPath, $info, FILE_APPEND);

  return $score;
}

/**
 * API pública principal
 *
 * @param string $pdfText  Text extret del PDF (pdftotext o similar)
 * @param string $pdfPath  Ruta al PDF (per proves de color o metadades)
 * @return array {
 *   rules: array<string,bool|null>,
 *   partials: array<string,int>, // 0-100 per regla
 *   score: int,                  // 0-100
 *   comments: string[],          // notes per a UI/admin
 *   meta: array<string,mixed>    // metadades útils (dates detectades, etc.)
 * }
 */
function run_heuristics(string $pdfText, string $pdfPath, ?array $opts = null): array {
  // normalitza opcions per evitar notices
  $opts = is_array($opts) ? $opts : [];
  $opts += [
    'ref'    => '<codi>',
    'host'   => 'riders.kinosonik.com',
    'versio' => null,
    // NOVETAT: permet desactivar el “bonus”/pes del repositori (comentaris i suggeriments també)
    'repo_bonus' => true,
  ];
  $norm = normalize_text($pdfText);

  // Classificador de tipus de document
  $rc = rider_confidence($norm);
  $docType = ($rc >= 60) ? 'rider' : (($rc >= 40) ? 'maybe_rider' : 'not_rider');

  // Senyals de text pobre → OCR recomanat
  $len = mb_strlen($norm, 'UTF-8');
  // Ajust: menys falsos positius. OCR si hi ha molt poques lletres/dígits o el text és mínim.
  $alnumCount = preg_match_all('/[A-Za-z0-9]/u', $norm);
  $ocrRecommended = ($len < 60) || ($alnumCount < 30);
  // molt poc text: probable PDF rasteritzat/escanejat

  // ── Tall directe: si no és un rider ───────────────────────────────
  $strong = preg_match('~\b(sm57|sm58|d112|beta\s?52|e906|md421|km184|re20|c414|di|xlr|foh|monitors?)\b~iu', $norm)
          && preg_match('~\b(kick|snare|tom|guitar|bass|keys?|piano|voz|veu|vocal)\b~iu', $norm);

  // Evita tallar manuals si hi ha clarament lèxic d’àudio/instrumentació (fort però no “strong”)
  $audioSmell = preg_match('~\b(mic|sm5[78]|d112|beta\s?52|e9(?:06|09|35|45)|md421|km184|re20|c414|di|xlr|kick|snare|guitar|bass|keys?|piano|voz|veu|vocal)\b~iu', $norm);
    if ($docType === 'not_rider' || (is_manual_like($norm) && !$strong && !$audioSmell)) {
    return [
      'rules'    => [],
      'partials' => [],
      'score'    => 0,
      'comments' => [
        'El document no sembla un rider tècnic. ' .
        'Si és un manual, contracte o qualsevol altre tipus de fitxer, no es pot analitzar amb la IA de riders.'
      ],
      'meta' => [
        'doc_type'         => 'not_rider',
        'rider_confidence' => $rc,
        'ocr_recommended'  => $ocrRecommended,
        'text_chars'       => $len,
      ],
    ];
  }

  // ── META: dates / versions (serveix per vigència temporal) ─────────────
  // Incloem el nom del fitxer per captar "2024-25", "2025.pdf", etc.
  $docName = mb_strtolower(basename($pdfPath), 'UTF-8');
  $dateMeta = dates_meta($norm . "\n" . $docName); // ['years'=>[...], 'latest_year'=>int|null, 'has_version'=>bool, 'is_recent'=>bool, 'age_years'=>int|null]
  // ── META: tecnic_so (explicita si sí/no o format tabulat) ──────────────
  $soundMeta = sound_tech_metrics($norm);
  // ── META: divisió d’equip (ítems per Promotor/Banda) ───────────────────
  $divScan = parse_divisio_sections($norm);
  $divMeta = divisio_items_meta($divScan);

  // es calcularà un bloc de suggeriments per a UI/correu

  $rules = [
    // 1) Contacte tècnic: nom + cognoms + email + telèfon
    'contacte_tecnic' => has_contact($norm),

    // 2) Data/versió i enllaç a rider actualitzat
    'data_versio'     => has_date_or_version($norm, $dateMeta), // incorpora vigència (≤2 anys)
    'repositori'      => has_repository_link($norm),

    // 3) Colors OK (apta per impressió B/N) — pot retornar null si no es pot avaluar
    'colors_ok'       => detect_colors_ok($pdfPath),

    // 4) Estructura de document tècnic (sense portades/ornament excessiu)
    'doc_tecnic_ok'   => is_technical_doc_shape($norm),

    // 5) Seccions clau
    'seccions_clau'   => has_main_sections($norm), // necessitats, stage plot, patch escenari + monitors

    // 6) Tècnic de so: especifica si en porten o no
    'tecnic_so'       => mentions_sound_tech($norm),

    // 7) Patch/llistat de canals coherent (contigu, una pàgina, imprimible)
    'patch_ok'        => has_patch_list($norm),

    // 8) Qui aporta què (promotor vs banda)
    'equip_divisio'   => mentions_promotor_vs_band($norm),

    // 9) Patch: instrument/font + micro/interfície + alternatives + suport
    'micro_altern'    => mentions_mic_alternatives($norm),
    // 10) Pes de fitxer (no és booleà pur; es converteix a parcial)
    'pes_fitxer'      => file_size_flag($pdfPath),
  ];

  // Ponderacions base (senzilles; ajustarem amb dades)
  $weights = [
    'contacte_tecnic' => 16,
    'data_versio'     => 8,   // ↓ lleu: no ha de castigar tant riders minimalistes
    //'repositori'      => 2,
    'colors_ok'       => 8,
    'doc_tecnic_ok'   => 6,   // ↓ menys pes: formes estètiques no han d’abaixar tant
    'seccions_clau'   => 16,  // ↑
    'tecnic_so'       => 10,
    'patch_ok'        => 18,  // ↑ consolida evidència forta
    'equip_divisio'   => 4,   // ↓ suau: molt sovint implícit a venues petites
    'micro_altern'    => 4,
    'pes_fitxer'      => 4,
  ];
  // Si el client no vol comptar “repositori”, posa el seu pes a 0
  if (empty($opts['repo_bonus'])) {
    $weights['repositori'] = 0;
  }

  // validació: suma exacta 100 (renormalitza si cal)
  $totalW = array_sum($weights);
  if ($totalW !== 100) {
    // normalitza (per si un futur afegeix regles)
    foreach ($weights as $k => $v) { $weights[$k] = (int)round(($v / max(1, $totalW)) * 100); }
  }

  // ── Fase B2: parcials 0..100 …
  $partials = compute_partials_b2($norm, $pdfPath, $rules, $dateMeta);
  $score = evaluate_heuristics($partials, $weights);

  $patchNum    = (int)($partials['patch_num'] ?? 0);
  $patchModels = (int)($partials['patch_models'] ?? 0);

  // BONUS suau per repositori si existeix (abans del gating FRE80)
  // Respecta $opts['repo_bonus']: si està desactivat, no sumem res.
  if (!empty($rules['repositori']) && !empty($opts['repo_bonus'])) {
    $score = min(100, $score + 2); // equival als 2 punts que tenia de pes
  }

  // ▼ Sincronitza algunes booleans amb parcials (evita inconsistències a la UI)
  foreach ([
    'patch_ok'      => 60,   // patch considerat "acceptable" si parcial ≥60
    'equip_divisio' => 55,   // divisió acceptable si parcial ≥55
    'micro_altern'  => 60,   // alternatives de micro acceptables si parcial ≥60
  ] as $k => $thr) {
    if (array_key_exists($k, $partials)) {
      $p = (int)$partials[$k];
      if     ($p >= $thr) $rules[$k] = true;
      elseif ($p === 0)   $rules[$k] = false; // mantén 'false' si és clarament 0
      // en cas contrari, deixa el valor que vingués de la heurística dura
    }
  }

  // "Evidència forta" de rider (baseline): patch consistent o seccions clau altes + olor d'àudio
  // Això evita notices més avall quan s'usa la variable abans del patch-boost.
  $strongRiderEvidence =
      ((($partials['patch_ok'] ?? 0) >= 75) && ($patchNum >= 2 || $patchModels >= 1))
      || ((($partials['seccions_clau'] ?? 0) >= 88) && $audioSmell);
  

  // Caps segons docType (evita segellar documents incorrectes) — només si NO hi ha evidència forta
  if (!$strongRiderEvidence) {
  if ($docType === 'not_rider') {
    $score = min($score, 50);
  } elseif ($docType === 'maybe_rider') {
    // si fa olor d'àudio, deixa pujar fins a 80
    $audioSmell = preg_match('~\b(mic|di|xlr|kick|snare|guitar|bass|voice|vocal|pa|foh|monitors?)\b~iu', $norm);
    $score = min($score, $audioSmell ? 86 : 65);
  }
  }

  // Si fa olor de manual, capa encara més — només si NO hi ha evidència forta
  if (is_manual_like($norm) && !$strongRiderEvidence) {
  // només penalitza si NO hi ha lèxic d’àudio
  if (!preg_match('~\b(mic|kick|snare|guitar|bass|voice|vocal|pa|foh)\b~iu', $norm)) {
    if ($docType === 'maybe_rider') $score = min($score, 40);
    if ($docType === 'rider')       $score = min($score, 45);
  }
  }

  // Bonus final "rider sa": ajuda a coronar >80 quan tot és coherent
  $healthy = (($partials['contacte_tecnic'] ?? 0) >= 80)
        && (($partials['patch_ok'] ?? 0) >= 70)
        && (($partials['seccions_clau'] ?? 0) >= 75)
        && (($partials['colors_ok'] ?? 50) >= 60);

  /* ───────── repo_bonus (suau, però ajuda a coronar) ───────── */
  $repoBonus = 0;
  if (($partials['repositori'] ?? 0) >= 60) {
    // +2 normal, +4 si tot pinta “sa”
    $repoBonus = $healthy ? 4 : 2;
    $score = min(100, $score + $repoBonus);
  }

  /* ───────── patch-boost (consolida riders bons) ───────── */
  if (($partials['patch_ok'] ?? 0) >= 88 && ($patchNum >= 2 || $patchModels >= 1)) {
    // Eleva seccions clau si han quedat curtes
    if (($partials['seccions_clau'] ?? 0) < 88) {
      $partials['seccions_clau'] = 88;
    }
    // Evidència forta (relaxa caps més avall)
    $strongRiderEvidence = true;
    @file_put_contents('/var/config/logs/riders/ia_debug.log',
      "[PATCH BOOST] hard=".($patchNum>=2||$patchModels>=1?"1":"0")." num={$patchNum} models={$patchModels}\n",
      FILE_APPEND
    );

    // Bonus suau (sense sòls 86/90)
    $score = min(100, $score + 3 + ($healthy ? 2 : 0));
  }

  /* ───────── Minimalist strong rider floor ─────────
   * Sòls per a riders minimalistes però impecables (electrònica/DI/stereo pairs).
   * Evita que faltes “cosmètiques” (doc_tecnic_ok, divisió explícita, data) els encallin <80.
   */
  $patchStrong   = (($partials['patch_ok'] ?? 0) >= 90) && (($partials['patch_num'] ?? 0) >= 4);
  $sectionsGood  = (($partials['seccions_clau'] ?? 0) >= 88);
  $contactsGood  = (($partials['contacte_tecnic'] ?? 0) >= 80);
  $soundGood     = (($partials['tecnic_so'] ?? 0) >= 80);
  if ($patchStrong && $sectionsGood && $contactsGood && $soundGood) {
    $floor = 84;
    if (($partials['micro_altern'] ?? 0) >= 90 && ($partials['colors_ok'] ?? 0) >= 80) {
      $floor = 87;
    }
    $score = max($score, $floor);
  }

  // Top-up suau si tot pinta sa però manca data/versió:
  if ($healthy && empty($rules['data_versio'])) {
    $score = min(100, $score + 3);
  }


  // Top-up quan tot és sa i l'única mancança és "repositori"
  if ($healthy) {
    $lackRepo = empty($rules['repositori']);
    $lackOther = (empty($rules['data_versio']) ? 1 : 0)
               + ((($partials['equip_divisio'] ?? 0) < 55 && empty($rules['equip_divisio'])) ? 1 : 0)
               + ((empty($rules['micro_altern']) && (($partials['patch_ok'] ?? 0) < 70) && !has_known_mics($norm)) ? 1 : 0);
    if ($lackRepo && $lackOther === 0) {
      $score = min(95, $score + 4);
    }
  }

  // Gate suau de printabilitat: si color dolent i sense data/versió, limita a 80,
  // tret que hi hagi evidència forta de rider (patch consistent).
  $colorsPartial = (int)($partials['colors_ok'] ?? 50);
  if ($colorsPartial < 60 && empty($rules['data_versio']) && !$strongRiderEvidence) {
    $score = min($score, 80);
  }

  // Prepara comments
  $comments = [];

  // Quan repo_bonus està desactivat, no volem que “repositori” generi
  // comentaris ni suggeriments. Fem una còpia dels rules per a UI.
  $rulesForUi = $rules;
  if (empty($opts['repo_bonus'])) {
    // Marca “repositori” com a complert de cara a UI/suggeriments
    $rulesForUi['repositori'] = true;
  }

  // Genera comentaris amb metadades enriquides (UNA sola vegada) sobre la còpia per a UI
  $comments = generate_comments($rulesForUi, $partials, [
    'dates'       => $dateMeta,
    'sound_tech'  => $soundMeta,
    'divisio'     => $divMeta,
  ]);

  // Afegeix el missatge d’OCR (si escau) després de generate_comments
  if ($ocrRecommended) {
    $comments[] = 'PDF amb molt poc text: probablement escanejat. Recomanat fer OCR abans de re-analitzar.';
  }

  // Bloc suggerit (bilingüe CA/ES + EN curt per FOH/MON) sobre la còpia per a UI
  $suggestion = build_suggestion_block(
    $rulesForUi,
    ['dates'=>$dateMeta, 'sound_tech'=>$soundMeta, 'divisio'=>$divMeta]
  );

    // ── Bloc suggerit per completar riders incomplets ──────────────
  $needBlock = [
    'need_tecnic_so' => !$rules['tecnic_so'],
    'need_divisio'   => !$rules['equip_divisio'],
    'need_micro_alt' => !$rules['micro_altern'],
  ];

  // suggereix només si falta almenys una d’aquestes o si no hi ha versió/repositori
  $shouldSuggest = (
    $needBlock['need_tecnic_so'] ||
    $needBlock['need_divisio']   ||
    $needBlock['need_micro_alt'] ||
    !$rules['data_versio']       ||
    // Si repo_bonus està desactivat, NO fem suggerir repositori
    ( !empty($opts['repo_bonus']) ? !$rules['repositori'] : false )
  );

  $suggestBlock = null;
  if ($shouldSuggest) {
    $suggestBlock = build_compact_block([
      'ref'            => $opts['ref'],
      'host'           => $opts['host'],
      'versio'         => $opts['versio'],
      'need_tecnic_so' => $needBlock['need_tecnic_so'],
      'need_divisio'   => $needBlock['need_divisio'],
      'need_micro_alt' => $needBlock['need_micro_alt'],
    ]);
  }

  if (($partials['equip_divisio'] ?? 0) < 55) {
    if (preg_match('~\b(pa|so(?:nido)?\s+de\s+sala|house\s+pa|venue\s+pa)\b~iu', $norm)) {
      $partials['equip_divisio'] = max($partials['equip_divisio'], 60);
    }
  }

  // ── DEBUG: bolcat de diagnosi a log
  $logPath = '/var/config/logs/riders/ia_debug.log';
  $dbg = [
    'when'    => date('Y-m-d H:i:s'),
    'file'    => basename($pdfPath),
    'docType' => $docType,
    'rc'      => $rc,
    'score'   => $score,
    'rules'   => $rules,
    'partials'=> $partials,
    'meta'    => [
      'latest_year' => $dateMeta['latest_year'] ?? null,
      'is_recent'   => $dateMeta['is_recent'] ?? null,
      'sound'       => $soundMeta,
      'divisio'     => $divMeta,
      'len'         => $len,
      'ocr'         => $ocrRecommended,
    ],
  ];
  @file_put_contents($logPath, json_encode($dbg, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);


  $hasStrong = $strongRiderEvidence && (($partials['contacte_tecnic'] ?? 0) >= 60);
  
  @file_put_contents('/var/config/logs/riders/ia_debug.log',
  sprintf("[FRE80] gate_check file=%s score=%d healthy=%d hasStrong=%d contact=%d patch=%d\n",
    basename($pdfPath), $score, $healthy?1:0, $hasStrong?1:0,
    (int)($partials['contacte_tecnic'] ?? -1), (int)($partials['patch_ok'] ?? -1)
  ),
  FILE_APPEND
  );
  // ── FRE80: aplica NOMÉS si el rider NO és clarament sa ───────────────────
  if (!($score >= 90 && $healthy) && !$hasStrong) {
    if ($score > 80) {
      // micro_altern només és mancança si NO hi ha models concrets i el patch no és sòlid
      $needsMicroAlt = empty($rules['micro_altern'])
                      && !has_known_mics($norm)
                      && (($partials['patch_ok'] ?? 0) < 70);

      // 🪪 Relaxació FRE80 si hi ha repositori i patch decent
      $relaxFre80 = (($partials['repositori'] ?? 0) >= 60) && (($partials['patch_ok'] ?? 0) >= 70);
      $equipSoftOK = (($partials['equip_divisio'] ?? 0) >= 55)
        || preg_match('~\b(so|sonido|pa)\s+de\s+sala\b|house\s+pa\b|venue\s+pa\b~iu', $norm);

      $missing = 0;
      if (empty($rules['data_versio'])) $missing++;
      if (empty($rules['equip_divisio']) && !$equipSoftOK) $missing++;
      if ($needsMicroAlt) $missing++;

      if (!$relaxFre80 && $missing >= 2) {
        $prevScore = $score;
        $score = 80 + (int)floor(max(0, $score - 80) * 0.35);
        @file_put_contents('/var/config/logs/riders/ia_debug.log',
          sprintf("[FRE80] applied file=%s prev=%d -> %d | miss=%d dv=%s div=%s micAltNeed=%s\n",
            basename($pdfPath), $prevScore, $score, $missing,
            $rules['data_versio'] ? '1':'0',
            $rules['equip_divisio'] ? '1':'0',
            $needsMicroAlt ? '1':'0'
          ),
          FILE_APPEND
        );
      } elseif ($missing === 1) {
        // Compressió suau (sense cap dur): 86–95 segons qualitat
        $cap = 86;
        if (($partials['patch_ok'] ?? 0) >= 80) $cap += 4;   // fins 90
        if ($healthy)                           $cap += 3;   // fins 93
        if ($repoBonus > 0)                     $cap += 2;   // fins 95
        $score = min($score, $cap);
      }
    }
  }

  // BOOST suau per riders minimalistes però coherents
  if ($score < 75
      && (($partials['patch_ok'] ?? 0) >= 60)
      && (($partials['tecnic_so'] ?? 0) >= 80)
      && (($partials['colors_ok'] ?? 0) >= 60)) {
    $score = min(75, $score + 4);
  }

  // Si el patch és molt sòlid, no penalitzis tant la forma del document
  if (($partials['doc_tecnic_ok'] ?? 0) < 60 && ($partials['patch_ok'] ?? 0) >= 90) {
    $partials['doc_tecnic_ok'] = max($partials['doc_tecnic_ok'] ?? 0, 60);
  }


  //$crc = crc32(basename($pdfPath));
  //$jitter = ($crc % 5) - 2; // −2..+2 estable per fitxer
  //$score = max(0, min(100, $score + $jitter));

  return [
    'rules'    => $rules,
    'partials' => $partials,
    'score'    => $score,
    'comments' => $comments,
    'meta'     => [
      'dates'            => $dateMeta,
      'sound_tech'       => $soundMeta,
      'divisio'          => $divMeta,
      'divisio_scan'     => $divScan,         // (opcional, útil per a admin/diagnosi)
      'doc_type'         => $docType,
      'rider_confidence' => $rc,
      'ocr_recommended'  => $ocrRecommended,
      'text_chars'       => $len,
    ],
    'suggestion_block'         => $suggestion,     // bloc “ric”
    'suggestion_block_compact' => $suggestBlock,   // bloc compacte (si escau)
  ];
}

/* ───────────────────────── Helpers de text ───────────────────────── */

function normalize_text(string $s): string {
  // normalitza espais i minúscules, conserva salts
  $s = preg_replace('/[ \t]+/u', ' ', $s);
  $s = preg_replace('/\R+/u', "\n", $s);
  return mb_strtolower(trim($s), 'UTF-8');
}
function flatten_text(string $s): string {
  // variant “aplastada” per PDFs amb paraules tallades línia a línia
  $s = mb_strtolower($s, 'UTF-8');
  $s = preg_replace('/\s+/u', ' ', $s);
  return trim($s);
}
function is_manual_like(string $t): bool {
  // Forts de manual/guia/soft
  $hits = 0;
  $rxs = [
    '~\b(manual|user\s*guide|software|firmware|installation|setup|configuration|licen[cs]e|warranty|safety\s+instructions?)\b~iu',
    '~\b(table\s*of\s*contents|contents|index|chapter\s+\d+|appendix|glossary|figure\s+\d+|fig\.\s*\d+)\b~iu',
    '~\b(troubleshooting|release\s*notes|revision\s*history|copyright|trademark)\b~iu',
    '~\b(ui|menu|button|led\s+indicator|screen|parameter|settings)\b~iu',
  ];
  foreach ($rxs as $rx) { if (preg_match($rx, $t)) $hits++; }
  // Considerem manual si n’hi ha ≥3, o si hi ha “manual|user guide|software” directament
  if ($hits >= 3) return true;
  if (preg_match('~\b(manual|user\s*guide|software)\b~iu', $t)) return true;
  return false;
}

/* * ───────────────────────── Regles (ara amb vigència) ────────────────────*/

function file_size_flag(string $pdfPath): ?bool {
  if (!is_readable($pdfPath)) return null;
  $bytes = @filesize($pdfPath);
  if (!is_int($bytes) || $bytes <= 0) return null;
  // true = “bo” (≤2MB), false = “dolent” (>2MB)
  return ($bytes <= 2 * 1024 * 1024);
}

function has_contact(string $t): bool {
  // Acceptem “contacte tècnic” si: EMAIL+TEL+Nom a prop, o EMAIL/TEL + rol (FOH/engineer…), 
  // i reforcem la cerca a l’últim bloc (footer).
  $emailRe = '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/iu';
  $telRe   = '/\+?\d[\d \-().]{6,}\d/u';
  $roleRe  = '/\b(foh|monitor(?:s)?|t[èe]cnic[ao]?|t[eé]cnico|engineer|sound\s+tech|stage\s+manager)\b/iu';
  $nameRe  = '/\b[ a-zà-ÿ\'\-]{2,}\s+[a-zà-ÿ\'\-]{2,}\b/iu';

  $hasEmailAll = (bool)preg_match($emailRe, $t);
  $hasTelAll   = (bool)preg_match($telRe,   $t);
  $hasRoleAll  = (bool)preg_match($roleRe,  $t);
  $hasNameAll  = (bool)preg_match($nameRe,  $t);

  // Footer focus (últimes ~18 línies)
  $lines = preg_split('/\R/u', $t) ?: [];
  $tail  = implode("\n", array_slice($lines, -18));
  $hasEmailTail = (bool)preg_match($emailRe, $tail);
  $hasTelTail   = (bool)preg_match($telRe,   $tail);
  $hasRoleTail  = (bool)preg_match($roleRe,  $tail);
  $hasNameTail  = (bool)preg_match($nameRe,  $tail);

  // Proximitat email–tel en una finestra
  $proximityOK = false;
  if (preg_match_all($emailRe, $t, $em, PREG_OFFSET_CAPTURE) &&
      preg_match_all($telRe,   $t, $tm, PREG_OFFSET_CAPTURE)) {
    foreach ($em[0] as [, $ePos]) {
      foreach ($tm[0] as [, $tPos]) {
        if (abs($ePos - $tPos) <= 140) { $proximityOK = true; break 2; }
      }
    }
  }

  $footerOK = ($hasEmailTail && ($hasTelTail || $hasRoleTail) && $hasNameTail);
  $globalOK = (($hasEmailAll && $hasTelAll && ($hasNameAll || $hasRoleAll)) || ($proximityOK && ($hasNameAll || $hasRoleAll)));
  // Relaxació: si al footer hi ha email i telèfon junts, accepta encara que el 'nom' no hagi estat clar
  if (!$footerOK && $hasEmailTail && $hasTelTail) {
    $footerOK = true;
  }

  return (bool)($footerOK || $globalOK || ($hasNameAll && $proximityOK));
}

function has_date_or_version(string $t, array $meta): bool {
  $hasVer = (bool)preg_match('~
    \b(versi[oó]?\s*\d+(\.\d+){0,2}|v\s*\d+(\.\d+){0,2}|rev(?:isi[oó]n)?\.?\s*[A-Z0-9]+|ed(?:ici[oó]n|ici[oó])?\.?\s*[A-Z0-9]+)\b
  ~iu', $t);

  // dd mmmm yyyy / mmmm yyyy (CA/ES/EN)
  $hasMonthYear = (bool)preg_match('~
    \b(0?[1-9]|[12]\d|3[01])\s+(de\s+)?[A-Za-zÀ-ÿ\.]+\s+(20[0-4]\d)\b|
    \b[A-Za-zÀ-ÿ\.]+\s+(20[0-4]\d)\b
  ~u', $t);

  $isRecent = (bool)($meta['is_recent'] ?? false);
  $hasAnyYearText = (bool)preg_match('~\b20[0-4]\d\b~u', $t);
  $hasAnyYearMeta = !empty($meta['latest_year']);

  return ($hasVer || $hasMonthYear || $isRecent || $hasAnyYearText || $hasAnyYearMeta);
}

function has_repository_link(string $t): bool {
  // Accepta dominis coneguts o bé un URL a prop de paraules clau de rider
  $repoHint = '(rider|riders|tech|technical|visualitza|visualiza|specs?)';
  $urlRx    = '~\b(?:https?://)?[a-z0-9.-]+\.[a-z]{2,}/\S+~iu';
  $okDom    = preg_match('~\b(riders\.kinosonik\.com|drive\.google\.com|dropbox\.com|dl\.dropboxusercontent\.com|bit\.ly|tinyurl\.com|linktr\.ee|beacons\.ai|campsite\.bio|solo\.to|withkoji\.com)\b~iu', $t);
  $urlAny   = preg_match($urlRx, $t);
  if ($okDom) return true;
  if ($urlAny && preg_match('~'.$repoHint.'.{0,80}'.$urlRx.'|'.$urlRx.'.{0,80}'.$repoHint.'~iu', $t)) return true;
  return false;
}

function detect_colors_ok(string $pdfPath): ?bool {
  // Heurística amb Poppler: si totes les imatges són GRAY i no detectem pistes de color, OK.
  // Nota: No detecta color de text vectorial (limitat), però ja és útil.
  $pdfimages = trim((string)@shell_exec('command -v pdfimages 2>/dev/null'));
  $name = mb_strtolower(basename($pdfPath), 'UTF-8');
  // Reconeix més variants al nom del fitxer que indiquen B/N
  if (preg_match('~\b(b&n|b[_-]?n|bw|b[_-]?w|no[-_]?color|grays?cale)\b~i', $name)) return true;
  
  if ($pdfimages === '') return null; // no avaluable si no tenim pdfimages

  $cmd = escapeshellcmd($pdfimages) . ' -list ' . escapeshellarg($pdfPath) . ' 2>/dev/null';
  $out = (string)@shell_exec($cmd);
  if ($out === '') return true; // sense imatges: molt probablement text pur B/N

  // Comptem imatges i colorspace
  $lines = preg_split('/\R/u', $out);
  $images = 0; $nonGray = 0;
  foreach ($lines as $ln) {
    // les files de dades de pdfimages acostumen a tenir una columna 'color' / 'cs'
    if (preg_match('/\b(?:image|page)\b/i', $ln)) continue; // capçalera
    if (preg_match('/\b(gray|rgb|rgba|cmyk|indexed)\b/i', $ln, $m)) {
      $images++;
      $cs = strtolower($m[1]);
      if ($cs !== 'gray') $nonGray++;
    }
  }
  if ($images === 0) return true;        // sense imatges: molt probable B/N
  if ($nonGray === 0) return true;       // totes GRAY
  return false;                          // hi ha imatges amb color
}

function has_known_mics(string $t): bool {
  // Afegim condensadors i vocal mics habituals que apareixen en riders minimalistes
  return (bool)preg_match('~\b('
    .'sm57|sm58|beta\s?58|beta\s?52|d112|e9(?:06|09|35|45)|e604|e904|e935|e945|e965|'
    .'md421|km184|re20|c414|sm7b|i5|m88|m201|d6|d4|d2|'
    .'kms\s?105|kms105|ksm9|beta\s?87a?|om7|om5|dpa\s?4099'
  .')\b~iu', $t);
}

function is_technical_doc_shape(string $t): bool {
  // Analitza les primeres línies com a possible portada
  $head = mb_substr($t, 0, 1200, 'UTF-8'); // primer ~1k caràcters

  // lèxic tècnic bàsic
  $hasTechWords = preg_match('/\b(rider|stage\s*plot|patch|input|output|channel|monitors?|backline|foh|di|mic|xlr|phantom)\b/iu', $t);

  // contingut de contacte o data (títol típic de rider)
  $hasHeaderUseful = preg_match('/(contact|foh|202\d|20[1-4]\d)/iu', $head);

  // Manual / software detection (com abans)
  $isManual = preg_match('/\b(manual|user\s*guide|software|installation|setup|table\s*of\s*contents|chapter|index)\b/iu', $t);

  // Només es penalitza si:
  //  - hi ha “cover/presentació” explícit, o
  //  - les primeres línies no tenen cap lèxic útil ni tècnic
  $looksLikeCover = (!$hasHeaderUseful && !$hasTechWords && mb_strlen($t, 'UTF-8') > 1000);

  return (bool)($hasTechWords && !$isManual && !$looksLikeCover);
}

function has_main_sections(string $t): bool {
  $flat = flatten_text($t);
  $patch    = preg_match('~\b(patch|inputs?|input\s*(list|sheet)|canals?|channels?|lista\s*de\s*canales?)\b~iu', $flat);
  $need     = preg_match('~\b(necessitats|needs|requisitos|especificaciones?\s*t[eé]cnicas?|technical\s*requirements?)\b~iu', $flat);
  $monitors = preg_match('~\b(monitors?|iem|in-?ears?|wedges?|falques?|cuñas?|sidefills?|retornos?)\b~iu', $flat);
  // Stage plot també com "plano/plànol de escenario/escenari"
  $stageAlt = preg_match('~\b(stage\s*(plot|plan)|stageplan|pl[aà]n[oò]\s+de\s+escenari[o]?|pl[aà]nol\s+d[ei]\s*escenari|plano\s*escenario)\b~iu', $flat);
  // Stage Plot passa a ser opcional. Si hi ha PATCH/INPUTS → OK.
  if ($patch) return true;
  // Alternativament, dues de les altres seccions també donen OK:
  $hits = ($need?1:0) + ($monitors?1:0) + ($stageAlt ? 1 : 0);
  return $patch || $hits >= 2;
}

function mentions_sound_tech(string $t): bool {
  // Usa mètriques ampliades (frase/taula/minimal) i retorna booleà
  $m = sound_tech_metrics($t);
  if ($m['explicit'] || $m['tabular'] || $m['has']) return true;
  // Detecció tolerant — “FOH Engineer”, “Sound engineer”, “Contact FOH”
  if (preg_match('~\b(foh|sound)\s*(engineer|tech|technician)\b~iu', $t)) return true;
  if (preg_match('~\bcontact\b.*\bfoh\b~iu', $t)) return true;
  // Variant a text “aplastat” per si el PDF trenca massa les línies
  $flat = flatten_text($t);
  if (preg_match('~\b(foh|sound)\s*(engineer|tech|technician)\b~iu', $flat)) return true;
  if (preg_match('~\b(técnic[oa]\s+de\s+sonido|t[èe]cnic[oa]\s+de\s+so)\b~iu', $flat)) return true;
  return false;
}

function has_patch_list(string $t): bool {
  // Pre-càlcul: línies “canal-like”
  // Més tolerant: compta també "1 kick" i bullets comuns
  $chanLike = preg_match_all(
    '~(^|\n)\s*\d{1,3}\s*(?:[:\.\-\)]|\||·|•|\)|\t|\s+)\s*~imu',
    $t
  );

  // Primer, prova el parser “formal”
  $entries = parse_patch_entries($t);
  $valid = 0;
  foreach ($entries as $e) {
    if ($e['desc_ok'] && ($e['mic_ok'] || $e['di_ok'])) $valid++;
  }
  if ($valid >= 2) return true; // amb 2 entrades ja és un patch real
  if ($valid === 1 && $chanLike >= 3) return true;

  // Fallback 1: canals numerats + descripció d’instrument encara que NO digui el micro
  // (regex de canals més tolerant)
  $descOnly = 0;
  foreach (preg_split('/\R/u', $t) as $ln) {
    if (
      preg_match('~^\s*(?:ch(?:an(?:nel)?)?|canal|in(?:put)?)?\s*\d{1,3}\b~iu', $ln) &&
      preg_match('~\b(veu|voz|vocal|vox|guitar|gtr|guitarra|baix|bass|bombo|kick|bd|snare|caixa|sd|tom|t1|t2|t3|ft|overhead|oh|hihat|hh|keys?|kbd|piano|tracks?|click)\b~iu', $ln)
    ) {
      $descOnly++;
    }
  }
  // Nombre de línies “canal-like” per donar crèdit de densitat
  $chanLike = preg_match_all(
    '~(^|\n)\s*\d{1,3}\s*(?:[:\.\-\)]|\||\)|\t|\s{1,3})\s+~imu',
    $t
  );
  if ($chanLike >= 4 && $descOnly >= 3) return true;
  if ($chanLike >= 3 && $descOnly >= 2) return true;

  // Fallback 2: molta olor d’instruments + micros dispersos al text
  $flat = flatten_text($t);
  $instrHits = preg_match_all('~\b(kick|bombo|snare|caixa|tom|overheads?|oh|hihat|charles|guitarra|guitar|gtr|baix|bass|keys?|kbd|piano|voz|veu|vocal|vox)\b~iu', $flat);
  $micHits   = preg_match_all('~\b(sm57|sm58|e9(?:06|09|35|45)|beta\s?52|d112|md421|km184|re20|c414|di|direct\s*box|line\s*box|xlr)\b~iu', $flat);
  if (($instrHits >= 6 && $micHits >= 4) || ($instrHits >= 8 && $micHits >= 6)) return true;

  return false;
}

function mentions_promotor_vs_band(string $t): bool {
  // Booleà robust: capçalera o frases de responsabilitat per ambdues parts
  $promHdr = preg_match('~(^|\n)\s*(promotor|organitzador[a]?|organització|organizacion|venue|sala|production|house)\s*[:\-]~iu', $t);
  $bandHdr = preg_match('~(^|\n)\s*(banda|grup|artista|band|backline)\s*[:\-]~iu', $t);
  $verb    = '(?:aporta|proporciona|proveeix|a\s+c[aà]rrec\s+de|provides|will\s+provide|to\s+provide|supply|supplies)';
  $promFr  = preg_match('~\b(promotor|promoter|venue|sala|organitzaci[oó]|organizador|production|house)\b.*?\b' . $verb . '\b~iu', $t);
  $bandFr  = preg_match('~\b(banda|grup|artista|band|backline)\b.*?\b' . $verb . '\b~iu', $t);
  $base = (bool)( ($promHdr || $promFr) && ($bandHdr || $bandFr) );
  $implicit = false;
  // Heurístiques implícites
  if (preg_match('~(proporcionat|proporcionats?|provided)\s+per\s+(la\s+)?(sala|promotor|venue|organitzaci[oó])~iu', $t)) $implicit = true;
  if (preg_match('~\b(a\s+c[aà]rrec\s+de|a\s+cargo\s+de|aportad[oa]\s+por|proporcionad[oa]\s+por)\s+(la\s+)?(sala|venue|promotor(?:a)?|organitzaci[oó]n|organización)\b~iu', $t)) $implicit = true;
  // ES clàssic: "backline propio", "equipo propio"
  if (preg_match('~\b(backline|equipo)\s+propio\b~iu', $t)) $implicit = true;
  // Venue/promotor implícit: "sonido de sala", "PA de sala"
  if (preg_match('~\b(pa|equipo\s+de\s+pa|sonido|monitores?)\s+de\s+sala\b~iu', $t)) $implicit = true;
  // Català: “so de sala / equip de sala”
  if (preg_match('~\b(so|equip(o)?)\s+de\s+sala\b~iu', $t)) $implicit = true;  // Altres fórmules freqüents
  if (preg_match('~\b(FOH|PA|monitores?)\b.*\b(de\s+sala|por\s+la\s+sala|house|venue)\b~iu', $t)) $implicit = true;
  $ok = (bool)($base || $implicit);

  // Implicit EN extres: "house/venue will provide", "provided by venue", "PA provided"
  if (!$ok) {
    if (preg_match('~\b(house|venue|promoter|production)\s+(will\s+)?provide(s|d)?\b~iu', $t)) {
      $ok = true;
    } elseif (preg_match('~\b(pa|sound\s*(system)?|monitors?)\s+(provided|supplied)\s+by\s+(house|venue|promoter|production)\b~iu', $t)) {
      $ok = true;
    } elseif (preg_match('~\b(backline|amps?|drums?)\s+(provided|supplied)\s+by\s+(band|artist)\b~iu', $t)) {
      $ok = true;
    }
  }

  return $ok;
}

function mentions_mic_alternatives(string $t): bool {
  // Si hi ha micros “coneguts”, no exigim alternatives.
  if (has_known_mics($t)) return true;
  // Si NO hi ha mics coneguts i hi ha senyal de microfonia genèrica, exigim “equivalent/alternativa” o parella.
  $genericMic = (bool)preg_match('~\b(mic|micro(?:fon[oa]?|phone)?)\b~iu', $t);
  $m = micro_alt_metrics($t, parse_patch_entries($t));
  if ($genericMic) return (bool)($m['pairs'] >= 1 || $m['has_equiv_word']);
  // Si ni tan sols parlen de micros, no forcem la regla.
  return true;
}

/* ───────────────────────── Agregació ───────────────────────── */

function evaluate_heuristics(array $partials, array $weights): int {
  $sum = 0.0;       // suma ponderada dels parcials existents
  $usedW = 0;       // suma de pesos de regles avaluades (p != null)
  foreach ($partials as $k => $p) {
    if ($p === null) continue; // no avaluable → no compta el seu pes
    $w = (int)($weights[$k] ?? 0);
    $usedW += $w;
    $sum += ($w * max(0, min(100, (int)$p))) / 100.0;
  }
  if ($usedW <= 0) return 0;
  // Renormalitza de “usedW” a “100”
  $score = (int)round(($sum / $usedW) * 100);
  return max(0, min(100, $score));
}

function generate_comments(array $rules, array $partials, array $meta = []): array {
  $out = [];
  foreach ($rules as $k => $val) {
    if ($val === true) continue;
    // etiquetes simples; demà les localitzarem a la UI:
    $labels = [
      'contacte_tecnic' => 'Falta contacte tècnic complet (nom+cognoms, email i telèfon).',
      'data_versio'     => 'Afegeix data o versió del document.',
      'repositori'      => 'Inclou un enllaç al rider actualitzat (repositori/visualitza.php).',
      'colors_ok'       => 'Document amb risc de problemes en impressió B/N (revisa colors i fons).',
      'doc_tecnic_ok'   => 'Evita portades/relleno; centra el document en la informació tècnica.',
      'seccions_clau'   => 'Falten seccions clau (necessitats, stage plot, patch escenari/monitors).',
      'tecnic_so'       => 'Especifica explícitament si porteu tècnic de so.',
      'patch_ok'        => 'Revisa la llista de canals (contigua, en una pàgina i clarament llegible).',
      'equip_divisio'   => 'Aclareix què aporta el promotor i què aporta la banda.',
      'micro_altern'    => 'Si no especifiqueu models concrets de micro, afegiu “alternativa/equivalent”.',
      'pes_fitxer'      => 'El fitxer supera 2 MB: recomanat comprimir o exportar sense fons/color.',
    ];
    $msg = $labels[$k] ?? ('Millora pendent: '.$k);
    // Per valors null (no avaluables), fem un missatge suau:
    if ($val === null) $msg .= ' (pendents de comprovació automàtica)';
    $out[] = $msg;
  }
  // Afegim matís de vigència si s’ha detectat any massa antic
  $dateMeta = $meta['dates'] ?? [];
  if (!empty($dateMeta['latest_year']) && ($dateMeta['is_recent'] ?? null) === false) {
    $y = (int)$dateMeta['latest_year'];
    $age = $dateMeta['age_years'] ?? null;
    $more = is_int($age) ? " (~{$age} anys)" : '';
    $out[] = "Possible desactualització: última data detectada $y$more. Recomanable revisar el rider.";
  }
  // Nota informativa: s’ha detectat explícitament que NO porten tècnic de so
  $st = $meta['sound_tech'] ?? [];
  if (!empty($st['explicit_neg'])) {
    $out[] = "S’ha detectat que **NO** porteu tècnic de so; confirmeu-ho i detalleu condicions de FOH/MON si escau.";
  }
  // Pista de UI: si hi ha ítems a ambdós blocs, suggerim revisar coherència
  $dv = $meta['divisio'] ?? [];
  if (!empty($dv['prom_items']) && !empty($dv['band_items'])) {
    $out[] = "Responsabilitats detectades per **Promotor** i **Banda**: reviseu que no hi hagi solapaments.";
  }
  return $out;
}

/* ───────────────────────── Fase B2: parcials 0..100 ─────────────────── */
function compute_partials_b2(string $t, string $pdfPath, array $rules, array $dateMeta): array {
  $partials = [];
  foreach ($rules as $k => $val) {
    $partials[$k] = $val === true ? 100 : ($val === false ? 0 : 50);
  }

  // Contacte: puntuació proporcional (email+tel+nom)
  $hasEmail = preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/u', $t) ? 1 : 0;
  $hasTel   = preg_match('/\+?\d[\d \-().]{6,}\d/u', $t) ? 1 : 0;
  $hasName = preg_match('/\b[ a-zà-ÿ\'\-]{2,}\s+[a-zà-ÿ\'\-]{2,}\b/u', $t) ? 1 : 0;
  $partials['contacte_tecnic'] = (int)round((($hasEmail + $hasTel + $hasName) / 3) * 100);

  // Data/versió: versió explícita = 100; si només any recent (≤2a) = 80; si antic (3–4a) = 40; molt antic/absent = 0
  $hasVer  = (bool)preg_match('/\b(v(er(sion)?)?\.?\s*\d+(\.\d+){0,2})\b/iu', $t);
  $isRecent = (bool)($dateMeta['is_recent'] ?? false);
  $curY = (int)date('Y');
  $hasYearRecentText = (bool)preg_match('~\b20(2[3-9]|3\d)\b~u', $t);
  $latestMetaYear = (int)($dateMeta['latest_year'] ?? 0);
  $hasYearRecentMeta = ($latestMetaYear >= ($curY - 2));
  $hasYearRecent = ($hasYearRecentText || $hasYearRecentMeta);
  $age = $dateMeta['age_years'] ?? null;
  if ($hasVer) {
    $partials['data_versio'] = 100;
  } elseif ($isRecent || $hasYearRecent) {
    $partials['data_versio'] = 90;
  } elseif (is_int($age) && $age >= 3 && $age <= 4) {
    $partials['data_versio'] = 40;
  } else {
    // si colors filename suggereix BW i no hi ha dates, no canvia això
    $partials['data_versio'] = $partials['data_versio'] ?? 0;
  }

  // Colors: null→50 (neutre), true→100, false→65
  $col = detect_colors_ok($pdfPath);
  if ($col === true) {
    $partials['colors_ok'] = 100;
  } elseif ($col === false) {
    $partials['colors_ok'] = 40;
  } else { // null
    $partials['colors_ok'] = 60;
  }

  // Seccions clau (B2): escalat discret 0/50/75/100 segons #hits
  // dins compute_partials_b2(), just abans de calcular $hits:
  $need     = preg_match('/\b(necessitats|needs|requisitos|technical(\s+)?requirements?)\b/iu', $t) ? 1 : 0;
  $stage    = preg_match('/\b(stage\s*plot|stage\s*plan|stageplan|escenari|escenario|scenario)\b/iu', $t) ? 1 : 0;
  $stageAlt = preg_match('~\b(stage\s*(plot|plan)|stageplan|pl[aà]n[oò]\s+de\s+escenari[o]?|pl[aà]nol\s+d[ei]\s*escenari|plano\s*escenario)\b~iu', $t) ? 1 : 0;
  $patch    = preg_match('/\b(patch|input\s*(list|sheet)|canals?|channels?)\b/iu', $t) ? 1 : 0;
  $monitors = preg_match('~\b(monitors?|retorns?|iem|in-?ears?|wedges?|falques?|cuñas?|sidefills?|aux(?:es)?)\b~iu', $t) ? 1 : 0;

  $hits = $need + (($stage || $stageAlt) ? 1 : 0) + $patch + $monitors;
  if ($hits <= 0)       { $partials['seccions_clau'] = 0; }
  elseif ($hits === 1)  { $partials['seccions_clau'] = 50; }
  elseif ($hits === 2)  { 
    $partials['seccions_clau'] = 75; 
  }
  else
  { $partials['seccions_clau'] = 100; }  // 3 o 4
  // Boost suau: si un dels dos hits és PATCH, puja a 88 (molt habitual en riders minimalistes)
  if ($hits === 2 && $patch && ($need || $monitors || $stage || $stageAlt)) {
  $partials['seccions_clau'] = max($partials['seccions_clau'], 82);
  }

  // 🔸 Sinergia PATCH + MON/NEEDS → seccions >=75
  // Si hi ha un patch acceptable (≥60) i mencions de monitors o necessitats,
  // assegura que les seccions clau no quedin massa baixes.
  if (($partials['seccions_clau'] ?? 0) < 75 && ($partials['patch_ok'] ?? 0) >= 60) {
    if ($monitors || $need) {
      $partials['seccions_clau'] = max($partials['seccions_clau'], 75);
    }
  }

  // Bonus minimalista: si hi ha MONITORS i tecnic_so és sòlid, eleva a 75
  if ($monitors && (($partials['tecnic_so'] ?? 0) >= 80)) {
    $partials['seccions_clau'] = max($partials['seccions_clau'], 75);
  }

  // tecnic_so: parcial qualitatiu segons expressivitat
  // 100: frase explícita (portem/sense/llevamos/we bring/without + technician/engineer)
  //  80: format tabulat/abreujat (FOH/MON: Band/Venue/Yes/No/Si/No o nom)
  //  60: menció mínima del rol sense afirmació clara
  $st = sound_tech_metrics($t);
  if ($st['explicit'])      { $partials['tecnic_so'] = 100; }
  elseif ($st['tabular'])   { $partials['tecnic_so'] = max($partials['tecnic_so'] ?? 0, 80); }
  elseif ($st['has'])       { $partials['tecnic_so'] = max($partials['tecnic_so'] ?? 0, 60); }
  else                      { $partials['tecnic_so'] = $partials['tecnic_so'] ?? 0; }
  // Nova norma: si “contacte_tecnic” existeix, considera mínim 60 a tecnic_so
  if (($partials['contacte_tecnic'] ?? 0) >= 80) {
    $partials['tecnic_so'] = max($partials['tecnic_so'] ?? 0, 60);
  }


  // Patch: nova puntuació per completesa de camps (no per quantitat de files)
  //   Per entrada:
  //     descripció OK → +40
  //     mic o DI      → +60
  //     (bonus) si mic i suport → +10 (cap a 100)
  //     (bonus) notes/indicadors (phantom/eq/…) → +5 (cap a 100)
  $entries = parse_patch_entries($t);
  $scores  = [];
  $numCount = 0;     // entrades amb número de canal
  $modelCount = 0;   // entrades amb model de micro conegut
  foreach ($entries as $e) {
    if ($e['chan'] !== null && $e['desc_ok'] && ($e['mic_ok'] || $e['di_ok'])) $numCount++;
    if ($e['mic_ok']) $modelCount++;
    $desc = $e['desc_ok'] ? 40 : 0;
    $io   = ($e['mic_ok'] || $e['di_ok']) ? 60 : 0;
    $bonus = 0;
    if ($e['mic_ok'] && $e['stand_ok']) $bonus += 10;
    if ($e['notes_ok']) $bonus += 5;
    $scores[] = min(100, $desc + $io + $bonus);
  }
  // Regla: necessitem almenys 2 entrades vàlides per considerar-lo avaluable amb mitjana
  $validScores = array_values(array_filter($scores, fn($s) => $s >= 60)); // 60≈té desc+mic/di
  if (count($validScores) >= 2) {
    $avg = (int)round(array_sum($validScores) / count($validScores));
    $partials['patch_ok'] = $avg; // no depèn del nombre total
  } else {
    // si només hi ha 1 entrada acceptable, parcial baix; 0 → 0
    if (count($validScores) === 1) {
      $partials['patch_ok'] = max($partials['patch_ok'] ?? 0, 60); // 1 línia vàlida ja és OK
    } else {
      $partials['patch_ok'] = $partials['patch_ok'] ?? 0;
    }
  }

  // Mini-boost per a llistes sense numeració:
  // si hi ha ≥3 entrades vàlides però cap línia “chan-like”, puja +5 (top 100).
  $chanLikeLoose = preg_match_all(
    '~(^|\n)\s*(?:ch(?:an(?:nel)?)?|canal|in(?:put)?)?\s*\d{1,3}\s*(?:[:\.\-\)]|\||·|•|\t|\s{1,3})\s+~imu',
    $t
  );
  if (count($validScores) >= 3 && $chanLikeLoose === 0) {
    $partials['patch_ok'] = min(100, ($partials['patch_ok'] ?? 0) + 5);
  }

  // BONUS riders petits: si hi ha 1-2 entrades vàlides i menció de monitors/needs, puja a llindar "usable"
  if (($partials['patch_ok'] ?? 0) >= 60) {
    $monitorsHit = (bool)preg_match('~\b(monitors?|iem|in-?ears?|wedges?|cuñas|sidefills?)\b~iu', $t);
    $needsHit    = (bool)preg_match('~\b(necessitats|needs|requisitos|technical\s*requirements?)\b~iu', $t);
    if ($monitorsHit || $needsHit) {
      $partials['patch_ok'] = max($partials['patch_ok'], 70);
    }
  }

  // compta quantes d'aquestes línies tenen una descripció d'instrument/font
  $descOnly = 0;
  foreach (preg_split('/\R/u', $t) as $ln) {
    if (
      preg_match('~^\s*(?:ch(?:an(?:nel)?)?|canal|in(?:put)?)?[\h\p{Zs}]*\d{1,3}\b~u', $ln) &&
      preg_match('~\b(veu|voz|vocal|vox|guitar|gtr|guitarra|baix|bass|bombo|kick|bd|snare|caixa|sd|tom|t1|t2|t3|ft|overhead|oh|hihat|hh|keys?|kbd|piano|tracks?|click)\b~iu', $ln)
    ) {
      $descOnly++;
    }
  }

  // 🚧 Cap de prudència: si NO hi ha cap model de micro i <2 canals numerats,
  // no deixis que patch_ok superi 85 (evita 90s artificials).
  if ((int)($partials['patch_ok'] ?? 0) > 85 && $modelCount === 0 && $numCount < 2) {
    $partials['patch_ok'] = 85;
  }
  // Exposa mètrica perquè run_heuristics pugui aplicar boosts amb criteri
  $partials['patch_num']   = $numCount;
  $partials['patch_models']= $modelCount;

  // Nombre de línies “canal-like” (per als crèdits de densitat més avall)
  $chanLike = preg_match_all(
    '~(^|\n)\s*\d{1,3}\s*(?:[:\.\-\)]|\||\)|\t|\s{1,3})\s+~imu',
    $t
  );

  // dona crèdit si hi ha prou densitat de canals amb descripció
  if ($chanLike >= 4 && $descOnly >= 3) {
    $partials['patch_ok'] = max($partials['patch_ok'] ?? 0, 65);
  } elseif ($chanLike >= 3 && $descOnly >= 2) {
    $partials['patch_ok'] = max($partials['patch_ok'] ?? 0, 55);
  }

  // 🔧 No castiguis “doc_tecnic_ok” en riders molt sòlids de patch
  if (($partials['doc_tecnic_ok'] ?? 0) < 60 && ($partials['patch_ok'] ?? 0) >= 90) {
    $partials['doc_tecnic_ok'] = max($partials['doc_tecnic_ok'] ?? 0, 60);
  }

  // Crèdit implícit suau a equip_divisio si hi ha senyals de PA/house/venue al text
  if (($partials['equip_divisio'] ?? 0) < 55 && preg_match('~\b(house|venue|pa\s+de\s+sala|so\s+de\s+sala|house\s+pa|venue\s+pa)\b~iu', $t)) {
    $partials['equip_divisio'] = max($partials['equip_divisio'] ?? 0, 55);
  }

  // Fallback extra: si hi ha seccions clau fortes i prou canals “like”, dona crèdit
  if (($partials['patch_ok'] ?? 0) < 60) {
    $flat2 = flatten_text($t);
    $instr2 = preg_match_all('~\b(kick|bombo|snare|caixa|tom|t1|t2|t3|ft|overheads?|oh|hihat|charles|gtr|guitar|guitarra|baix|bass|keys?|kbd|piano|voz|veu|vocal|vox)\b~iu', $flat2);
    $chanLoose = preg_match_all('~(^|\n)\s*\d{1,3}[\h\p{Zs}]*(?:[:\.\-\)]|\||·|•)?[\h\p{Zs}]+~u', $t);
    if ((int)$partials['seccions_clau'] >= 75 && $instr2 >= 6 && $chanLoose >= 3) {
      $partials['patch_ok'] = max($partials['patch_ok'] ?? 0, 62);
    } elseif ($instr2 >= 8 && $chanLoose >= 4) {
      $partials['patch_ok'] = max($partials['patch_ok'] ?? 0, 60);
    }
  }

  // Bonus: +10 a patch si hi ha parelles d'alternativa (SM57 o e906, D112/Beta52, DI o Line box)
  // o bé apareix “o equivalent/alternativa/similar”
  {
    $micList = '(?:sm57|sm58|e906|e609|e904|beta\s?52|b52|d112|md421|km184|re20|u87|c414|sm7b|i5|m88|e935|e945|m201|m80|m81|d6|d4|d2|qlxd|axient|ew500|pga|beta\s?58)';
    $diLex   = '(?:di|reamp|line(?:\s*box)?)';
    $orSep   = '(?:\/|,|;|\s+o\s+|\s+or\s+)';
    $equivRx = '~\b(o\s+equivalent|equivalente|alternati(?:u|va)|similar(?:\s+a)?|any\s+similar)\b~iu';
    $pairs = 0;
    $pairs += preg_match_all('~\b'.$micList.'\b'.$orSep.'\b'.$micList.'\b~iu', $t, $m1);
    $pairs += preg_match_all('~\b'.$micList.'\b'.$orSep.'\b'.$diLex.'\b~iu',    $t, $m2);
    $pairs += preg_match_all('~\b'.$diLex.'\b'.$orSep.'\b'.$micList.'\b~iu',    $t, $m3);
    $pairs += preg_match_all('~\b'.$diLex.'\b'.$orSep.'\b'.$diLex.'\b~iu',      $t, $m4);
    $hasEquiv = (bool)preg_match($equivRx, $t);
    if ($pairs >= 1 || $hasEquiv) {
      $partials['patch_ok'] = min(100, ($partials['patch_ok'] ?? 0) + 10);
    }
  }

  // Fallback semàntic per a patch quan el text està molt “trinxat”
  if (($partials['patch_ok'] ?? 0) <= 70) {
    $flat = flatten_text($t);
    $instrHits = preg_match_all('~\b(kick|bombo|snare|caixa|tom|overheads?|oh|hihat|charles|guitarra|guitar|baix|bass|keys?|piano|voz|veu|vocal)\b~iu', $flat);
    $micHits   = preg_match_all('~\b(sm57|sm58|e9(?:06|09|35|45)|beta\s?52|d112|md421|km184|re20|c414|di|direct\s*box|line\s*box|xlr)\b~iu', $flat);
    if ($instrHits >= 8 && $micHits >= 6) {
      $partials['patch_ok'] = max($partials['patch_ok'] ?? 0, 72);
    } elseif ($instrHits >= 6 && $micHits >= 4) {
      $partials['patch_ok'] = max($partials['patch_ok'] ?? 0, 65);
    }
  }

  // equip_divisio: puntuació qualitativa per blocs (Promotor/Banda)
  $div = parse_divisio_sections($t);
  $scorePB = ['prom' => 0, 'band' => 0];
  foreach (['prom','band'] as $who) {
    $blk = $div[$who];
    $present = $blk['present'];
    $s = 0;
    if ($present) {
      // Base per presència del rol
      $s += 30; // cobertura mínima
      if ($blk['has_verb']) $s += 10;         // “aporta/proporciona/a càrrec de…”
      // Ítems
      if ($blk['items'] >= 2)      $s += 10;
      elseif ($blk['items'] === 1) $s += 5;
      // Exclusions clares
      if ($blk['exclusions']) $s += 5;
      // Contacte associat
      if ($blk['contact'])   $s += 5;
      if ($s > 50) $s = 50;  // top per bloc
    }
    $scorePB[$who] = $s;
  }
  // Si només hi ha un bloc present sense l’altre, mantenim una meitat del total
  $partials['equip_divisio'] = (int)min(100, $scorePB['prom'] + $scorePB['band']);
  // Si hi ha exactament un rol present i el seu bloc és pobre, no superem 50
  if (($div['prom']['present'] xor $div['band']['present'])) {
  // Heurística: si hi ha símptomes clars de “equip de sala”
  // o el bloc present té prou substància, no retallar tan agressiu
  $implicitVenue = (bool)preg_match('~\b(pa|equipo\s+de\s+pa|so\s+de\s+sala|sound\s+system|line\s*array|microfonia\s+est[àa]ndard|standard\s+mics?|di|direct\s*box|pies\s+de\s+micro|cablejat|cableado|corr?ent|schuko|cee)\b~iu', $t);
  $oneSide = $div['prom']['present'] ? 'prom' : 'band';
  $hasItems = (int)($div[$oneSide]['items'] ?? 0);

  if ($implicitVenue || $hasItems >= 2) {
  // retall suau: com a màxim 65 si només hi ha un bloc clar
  $partials['equip_divisio'] = min(65, $partials['equip_divisio'] ?? 65);
  }
  // FIX: no repetir $implicitVenue a l’elseif; només regex d’implicits
  elseif (preg_match('~\b(so|sonido|pa)\s*(de\s+)?sala\b|house\s+(pa|sound)|venue\s+pa\b~iu', $t)) {
  $partials['equip_divisio'] = max($partials['equip_divisio'] ?? 0, 60);
  } else {
    // cas pobre: manté el top a 50
    $partials['equip_divisio'] = min(50, $partials['equip_divisio'] ?? 50);
  }
  }

  // Soft cap als patches excel·lents per donar més granularitat
  if (isset($partials['patch_ok'])) {
    $partials['patch_ok'] = min(96, (int)$partials['patch_ok']);
  }

  /* --------- Soft-OK extra per a equip_divisio amb HOUSE/VENUE PA ---------- */
  // Si encara està baix, detecta fórmules típiques i eleva a 60 com a mínim.
  if (($partials['equip_divisio'] ?? 0) < 60) {
    $softVenuePA = (bool)preg_match('~
      \b(house|venue|promoter|production)\b.*\b(pa|sound\s*system|front\s*system|line\s*array|monitors?|wedges?|iem)\b
      |\b(pa|sound\s*system|monitors?)\b.*\b(provided|supplied)\b.*\b(house|venue|promoter|production)\b
      |\b(so|sonido)\s+de\s+sala\b
      |\bhouse\s*pa\b|\bvenue\s*pa\b|\bpa\s+de\s+sala\b|\bequipo\s+de\s+pa\b
    ~iu', $t);
    if ($softVenuePA) {
      // si el patch o seccions clau són sòlids, considera que la divisió és acceptable
      if ((int)($partials['patch_ok'] ?? 0) >= 60 || (int)($partials['seccions_clau'] ?? 0) >= 75) {
        $partials['equip_divisio'] = max(60, (int)($partials['equip_divisio'] ?? 0));
      } else {
        // encara que no, dóna un petit crèdit
        $partials['equip_divisio'] = max(55, (int)($partials['equip_divisio'] ?? 0));
      }
    }
  }

  // Bonus: +5 si hi ha ítems normalitzats a ambdós blocs (Promotor i Banda)
  $promHasItems = !empty($div['prom']['norm_items']);
  $bandHasItems = !empty($div['band']['norm_items']);
  if ($promHasItems && $bandHasItems) {
    $partials['equip_divisio'] = min(100, ($partials['equip_divisio'] ?? 0) + 5);
  }

  /* ───────── micro_altern: alternatives/parelles de mic o DI ───────── */
  // Criteri:
  //  - Booleà: true si hi ha com a mínim 1 parella (SM57 o e906, D112/Beta52, …)
  //           o apareix “o equivalent/alternativa/similar”.
  //  - Parcial:
  //      0     : cap senyal
  //      60    : 1 entrada amb parella o “equivalent”
  //      80    : 2 entrades amb parelles/indicadors
  //      100   : ≥3 entrades o parelles clares repetides
  $microM = micro_alt_metrics($t, $entries ?? parse_patch_entries($t));
  if ($microM['pairs'] >= 3)       { $partials['micro_altern'] = 100; }
  elseif ($microM['pairs'] >= 2)   { $partials['micro_altern'] = 80; }
  elseif ($microM['pairs'] >= 1)   { $partials['micro_altern'] = 60; }
  elseif ($microM['has_equiv_word']) { $partials['micro_altern'] = max($partials['micro_altern'] ?? 0, 60); }
  else                              { $partials['micro_altern'] = $partials['micro_altern'] ?? 0; }

  // Crèdit parcial per densitat de models concrets (implica flexibilitat)
  if (($partials['micro_altern'] ?? 0) < 60) {
    $flat = flatten_text($t);
    $models = preg_match_all('~\b('
      .'sm57|sm58|beta\s?58|e9(?:06|09|35|45)|e604|e904|e935|e945|e965|'
      .'md421|km184|re20|c414|beta\s?52|d112|i5|m88|m201|d6|d4|d2|'
      .'kms\s?105|ksm9|beta\s?87a?|om7|om5|dpa\s?4099'
    .')\b~iu', $flat);
    if ($models >= 10) {
      $partials['micro_altern'] = max($partials['micro_altern'] ?? 0, 60);
    } elseif ($models >= 6) {
      $partials['micro_altern'] = max($partials['micro_altern'] ?? 0, 45);
    }
  }
  // Baseline amable per a “microfonia estàndard” encara que no digui "o equivalent"
  if (($partials['micro_altern'] ?? 0) < 50) {
    if (preg_match('~\b(microfonia\s+est[àa]ndard|microfon[ií]a\s+est[áa]ndar|standard\s+mics?)\b~iu', $t)) {
      $partials['micro_altern'] = max($partials['micro_altern'] ?? 0, 48);
    }
  }

  // pes_fitxer → parcial suau: ≤2MB = 100; 2–6MB = 60; >6MB = 30
  if (array_key_exists('pes_fitxer', $rules)) {
    if ($rules['pes_fitxer'] === true) {
      $partials['pes_fitxer'] = 100;
    } elseif ($rules['pes_fitxer'] === false) {
      // tornem a mirar el pes exacte per graduar
      $sz = @filesize($pdfPath);
      if (is_int($sz)) {
        if ($sz > 6 * 1024 * 1024)      $partials['pes_fitxer'] = 30;
        elseif ($sz > 2 * 1024 * 1024)  $partials['pes_fitxer'] = 60;
        else                             $partials['pes_fitxer'] = 100;
      } else {
        $partials['pes_fitxer'] = 60;
      }
    } else {
      $partials['pes_fitxer'] = 60; // indeterminat → neutre-baix
    }
  }

  // DEBUG patch: bolcat resum de deteccions
  try {
    $logPath = '/var/config/logs/riders/ia_debug.log';
    $chanLikeDbg = preg_match_all('~(^|\n)\s*\d{1,3}\s*(?:[:\.\-\)]|\||\)|\t|\s{1,3})\s+~imu', $t);
    $entriesDbg = parse_patch_entries($t);
    $validDbg = 0; foreach ($entriesDbg as $e) { if ($e['desc_ok'] && ($e['mic_ok'] || $e['di_ok'])) $validDbg++; }
    $snippet = '';
    $countSnip = 0;
    foreach (preg_split('/\R/u', $t) as $ln) {
      if (preg_match('~^\s*(?:ch|canal|in(?:put)?)?\s*\d{1,3}\b~iu', $ln)) {
        $snippet .= rtrim(mb_substr($ln, 0, 160, 'UTF-8'))."\n";
        if (++$countSnip >= 6) break;
      }
    }
    $dbg = sprintf("[PATCH DBG] chanLike=%d validEntries=%d patchPartial=%d\n%s",
      $chanLikeDbg, $validDbg, (int)($partials['patch_ok'] ?? -1), $snippet
    );
    @file_put_contents($logPath, $dbg, FILE_APPEND);
  } catch (\Throwable $e) {
    // no-op
}

  return $partials;
}

/* ───────────────── sound_tech: mètriques frase/taula/minimal ───────────────── */
function sound_tech_metrics(string $t): array {
  // Frases positives/negatives (CA/ES/EN) amb “sound technician/tech/engineer”
    // Frases positives/negatives (CA/ES/EN) amb “sound technician/tech/engineer”
  $neg = (bool)preg_match(
    '~\b(?:'
      .'no\s+(?:sound\s+(?:technician|tech|engineer))'
      .'|sense\s+t[èe]cnic[ao]?\s*(?:de\s+(?:so|sonido))?'
      .'|sin\s+t[ée]cnic[ao]?\s*(?:de\s+sonido)?'
      .'|without\s+(?:foh|sound)\s+(?:technician|tech|engineer)'
    .')\b~iu', $t);

  $pos = (bool)preg_match(
    '~\b(?:'
      // CA/ES
      .'t[èe]cnic[ao]?\s+de\s+(?:so|foh|monitors?)'
      .'|enginyer[ao]?\s+de\s+(?:so|foh|monitors?)'
      .'|t[ée]cnico|ingenier[oa]\s+de\s+(?:sonido|foh|monitores?)'
      .'|t[èe]cnic[ao]?\s*propi[oa]?|t[ée]cnico\s*propio'
      .'|port(?:em|o|a)m?\s+t[èe]cnic[ao]?'
      .'|llevamos\s+t[ée]cnic[oa]?'
      // EN
      .'|we\s+bring\s+(?:a\s+)?(?:sound|foh)\s+(?:technician|tech|engineer)'
      .'|(?:sound|foh|monitor)\s+(?:technician|tech|engineer)'
    .')\b~iu', $t);

  // Formats tabulats/abreujats (FOH/MON amb valors curts)
  // Exemples: "FOH: Band", "MON: Venue", "FOH Engineer: Sí/No", "FOH - Banda", "FOH/Band, MON/Venue"
  $tabYesNo = '(?:yes|no|sí|si)';
  $tabActor = '(?:band|banda|grup|venue|sala|promoter|promotor|house|production|organització|organizacion|organización)';
  $tabName  = '[A-Za-zÀ-ÿ\'\.\- ]{2,40}';
  $tabKey   = '(?:foh|front\s*of\s*house|mon|monitor(?:s)?)';
  $tabRole  = '(?:engineer|tech|technician)?';

  $tabular = false;
  // línies tipus "FOH: X"
  $tabular = $tabular || (bool)preg_match('~(^|\n)\s*'.$tabKey.'\s*'.$tabRole.'\s*:\s*(?:'.$tabYesNo.'|'.$tabActor.'|'.$tabName.'(?:\s*\('.$tabActor.'\))?)\b~imu', $t);
  // formats amb guions o punts "FOH - Band" / "MON · Venue"
  $tabular = $tabular || (bool)preg_match('~(^|\n)\s*'.$tabKey.'\s*'.$tabRole.'\s*[-–·]\s*(?:'.$tabYesNo.'|'.$tabActor.'|'.$tabName.')\b~imu', $t);
  // parelles FOH/MON a la mateixa línia
  $tabular = $tabular || (bool)preg_match('~\bfoh\b[^/\n]{0,60}/[^/\n]{0,60}\bmon(?:itor(?:s)?)?\b~iu', $t);

  // NOVETAT: “Contact: FOH …” / “FOH Engineer: … <email/phone>”
  $contactFoh = (bool)preg_match('~(^|\n)\s*(contact|contacte)\s*:\s*(.*\bfoh\b.*)$~imu', $t);
  $fohEngineerLine = (bool)preg_match('~(^|\n)\s*foh\s*(?:engineer|tech|technician)?\s*:\s*.+$~imu', $t);
  if ($contactFoh || $fohEngineerLine) {
    $tabular = true;
  }

  // Extreure FOH/MON owners si hi ha format tabulat
  $owner = ['foh'=>null,'mon'=>null];
  if ($tabular) {
    if (preg_match('~(^|\n)\s*foh\s*'.$tabRole.'\s*[:\-–·]\s*(.+)$~imu', $t, $m)) {
      $val = trim($m[2]); $owner['foh'] = preg_split('~[,\|/]~', $val)[0] ?? $val;
    }
    if (preg_match('~(^|\n)\s*mon(?:itor(?:s)?)?\s*'.$tabRole.'\s*[:\-–·]\s*(.+)$~imu', $t, $m)) {
      $val = trim($m[2]); $owner['mon'] = preg_split('~[,\|/]~', $val)[0] ?? $val;
    }
    // normalitza valors coneguts
    foreach (['foh','mon'] as $k) {
      if (!is_string($owner[$k])) continue;
      $v = mb_strtolower($owner[$k], 'UTF-8');
      if (preg_match('~\b(band|banda|grup)\b~u', $v))   $owner[$k] = 'Band';
      elseif (preg_match('~\b(venue|sala|promoter|promotor|house|production)\b~u', $v)) $owner[$k] = 'Venue';
    }
  }

  $has = ($neg || $pos || $tabular);
  // Fallback suau: si hi ha lèxic FOH/MON al document, considera 'has' (per donar 60 punts a parcials)
  if (!$has && preg_match('~\b(foh|front\s*of\s*house|mon|monitors?)\b~iu', $t)) {
    $has = true;
  }
  return [
    'has'         => $has,
    'explicit'    => ($neg || $pos),
    'explicit_pos'=> $pos,
    'explicit_neg'=> $neg,
    'tabular'     => (!$neg && !$pos && $tabular),
    'foh_owner'   => $owner['foh'],
    'mon_owner'   => $owner['mon'],
  ];
}

/* ───────── equip_divisio: parseig de blocs Promotor/Banda i mètriques ───────── */
function parse_divisio_sections(string $t): array {
  $lines = preg_split('/\R/u', $t) ?: [];
  // Cosir files: si una línia és només un número i la següent té text → uneix
  $stitched = [];
  for ($i = 0; $i < count($lines); $i++) {
    $ln = trim((string)$lines[$i]);
    if (preg_match('~^\d{1,3}$~u', $ln) && isset($lines[$i+1]) && trim((string)$lines[$i+1]) !== '') {
      $stitched[] = $ln . ': ' . trim((string)$lines[$i+1]);
      $i++; // salta la següent (ja cosida)
    } else {
      $stitched[] = $lines[$i];
    }
  }
  $lines = $stitched;
  $promKeys = '(promotor|promotora|promoter|organitzador[a]?|organitzaci[oó]|organizacion|organización|venue|sala|producci[oó]n?|producci[oó]|production|house|festival|ajuntament|ayuntamiento)';
  $bandKeys = '(banda|grup|grupo|artista|artista\s+principal|band|backline)';
  $verbRx   = '~\b(?:aporta(?:r[àa])?|proporciona|proveeix|facilita|posa|suministra|a\s+c[aà]rrec\s+de|corre\s+a\s+cargo\s+de|provides|will\s+provide|to\s+provide|supply|supplies|se\s+encarga\s+de)\b~iu';
  $headProm = '/^\s*'.$promKeys.'\s*[:\-]\s*$/iu';
  $headBand = '/^\s*'.$bandKeys.'\s*[:\-]\s*$/iu';
  $itemRx   = '/^\s*(?:[-–•·\*]|\d+[\.\)\-])\s+(.+)$/u';
  $stripBulletRx = '/^\s*(?:[-–•·\*]|\d+[\.\)\-])\s*/u';
  $exclRx   = '/\b(no\s+incl[oò]s|no\s+aporta|excl[oò]s|excluido|not\s+included|excludes?)\b/iu';
  $emailRx  = '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/iu';
  $telRx    = '/\+?\d[\d \-().]{6,}\d/u';

  $res = [
    'prom' => ['present'=>false,'has_verb'=>false,'items'=>0,'exclusions'=>false,'contact'=>false,'raw_items'=>[],'norm_items'=>[]],
    'band' => ['present'=>false,'has_verb'=>false,'items'=>0,'exclusions'=>false,'contact'=>false,'raw_items'=>[],'norm_items'=>[]],
  ];

  // 1) Detecta capçaleres i captura blocs contigus fins la propera capçalera/salt fort
  $current = null;
  $buffer  = ['prom'=>[],'band'=>[]];
  foreach ($lines as $ln) {
    if (preg_match($headProm, $ln)) { $current = 'prom'; $res['prom']['present'] = true; continue; }
    if (preg_match($headBand, $ln)) { $current = 'band'; $res['band']['present'] = true; continue; }
    // Talls d’altres seccions habituals
    if (preg_match('/^\s*(patch|input\s*list|monitors?|foh|backline|power|hospitality|transport|escenari|stage\s*plot|stage\s*plan)\b/iu', $ln)) {
      $current = null; continue;
    }
    if ($current) $buffer[$current][] = $ln;
  }

  // 2) Si no hi ha capçaleres, intenta trobar frases “rol + verb”
  $joined = $t;
  if (!$res['prom']['present']) $res['prom']['present'] = (bool)preg_match('/\b'.$promKeys.'\b.*'.$verbRx.'/iu', $joined);
  if (!$res['band']['present']) $res['band']['present'] = (bool)preg_match('/\b'.$bandKeys.'\b.*'.$verbRx.'/iu', $joined);

  // 2.1) Senyals implícits (sense capçalera ni verb explícit)
  // PROMOTOR/VENUE: PA professional / monitor needs / wedges / IEM / “powerful enough” / “front system”
  if (!$res['prom']['present']) {
    $promImplicit = (bool)preg_match('~
      (professional\s+pa|fully\s+functional\s+pa|pa\s+system|main\s+(?:pa|system)|front\s*system|line\s*array)|
      (monitor\s+needs?|wedges?|cuñas|in-?ears?|iem)|
      (powerful\s+enough)
    ~iu', $joined);
    if ($promImplicit) $res['prom']['present'] = true;
  }
  // BANDA: “brings his/our own”, “we bring our own”, “own cymbals/amps/backline”
  if (!$res['band']['present']) {
    $bandImplicit = (bool)preg_match('~
      (brings?\s+his\s+own|bring\s+our\s+own|own\s+(?:cymbals?|amp[s]?|backline|mics?))
    ~iu', $joined);
    if ($bandImplicit) $res['band']['present'] = true;
  }

  // 3) Mètriques per bloc (capçalera o frases)
  foreach (['prom','band'] as $who) {
    $blkTxt = trim(implode("\n", $buffer[$who]));
    if ($blkTxt === '') {
      // Si no hi ha buffer (no capçalera), usa finestres locals al voltant de la primera coincidència
      if ($res[$who]['present']) {
        $roleRx = ($who === 'prom') ? '/'.$promKeys.'/iu' : '/'.$bandKeys.'/iu';
        if (preg_match($roleRx, $joined, $m, PREG_OFFSET_CAPTURE)) {
          $pos = $m[0][1];
          $start = max(0, $pos - 400);
          $blkTxt = mb_substr($joined, $start, 900, 'UTF-8');
        }
      }
    }
    if ($blkTxt !== '') {
      // Verb de responsabilitat
      $res[$who]['has_verb'] = (bool)preg_match($verbRx, $blkTxt);
      // Items (bulleted o numerats)
      $items = 0;
      $rawItems = [];
      foreach (preg_split('/\n/u', $blkTxt) as $bline) {
        if (preg_match($itemRx, $bline, $mm)) {
            // via A: grup capturat si el regex té (.+)
            $piece = isset($mm[1]) ? $mm[1] : preg_replace($stripBulletRx, '', $bline);
            $piece = trim((string)$piece);
            if ($piece !== '') {
            $items++;
            $rawItems[] = $piece;
            }
        }
      }
      // Si no hi ha punts, prova separar per comes/punt i coma
      if ($items === 0) {
        $parts = preg_split('/[,;]\s*/u', preg_replace('/\s+/', ' ', $blkTxt)) ?: [];
        // compta fragments “llargs” com a item (evitant buits)
        foreach ($parts as $p) {
          $pt = trim((string)$p);
          if ($pt !== '' && mb_strlen($pt, 'UTF-8') >= 8) {
            $items++;
            $rawItems[] = $pt;
          }
        }
        if ($items > 6) $items = 6; // evita inflar per text llarg
      }
      $res[$who]['items'] = $items;
      // Exemples d'ítems en brut i normalitzats
      $res[$who]['raw_items']  = $rawItems;
      $norm = [];
      foreach ($rawItems as $ri) {
        $lab = normalize_divisio_item($ri);
        if ($lab !== null) $norm[] = $lab;
      }
      $res[$who]['norm_items'] = array_values(array_unique($norm));
      // Exclusions
      $res[$who]['exclusions'] = (bool)preg_match($exclRx, $blkTxt);
      // Contacte
      $res[$who]['contact'] = (bool)(preg_match($emailRx, $blkTxt) || preg_match($telRx, $blkTxt));
    }
  }
  return $res;
}

/* ───────── divisio: normalització d'ítems i meta per a UI ───────── */
function normalize_divisio_item(string $s): ?string {
  $x = mb_strtolower($s, 'UTF-8');
  // neteja bàsica
  $x = preg_replace('~[\.\(\)\[\]]~u', ' ', $x);
  $x = preg_replace('~\s+~u', ' ', trim($x));
  // mapping per etiquetes canòniques
  $map = [
    'pa'           => '~\b(pa|p\.?\s*a\.?|main\s*(?:pa|system)|line\s*array|front\s*system)\b~u',
    'foh_desk'     => '~\b(foh\s*(?:desk|console|mixer)|front\s*of\s*house\s*(?:desk|console)|midas|profile|cl\d+|ql\d+|vi\d+)\b~u',
    'monitors'     => '~\b(monitors?|monitoratge|monitorado)\b~u',
    'wedges'       => '~\b(wedges?|cuñas|falques?|floor\s*monitors?)\b~u',
    'in_ears'      => '~\b(in-?ears?|iem|in\s*ears)\b~u',
    'rf'           => '~\b(rf|wireless|inal[áa]mbric[oa]s?|radio\s*mics?)\b~u',
    'microphones'  => '~\b(micro(?:fonia)?s?|mic(?:s)?|microphones?)\b~u',
    'di'           => '~\b(di|direct\s*box|line\s*box)\b~u',
    'cables'       => '~\b(cables?|manguera|multicor(?:e)?|snake)\b~u',
    'stands'       => '~\b(peus?|suports?|stands?|jirafes?)\b~u',
    'backline'     => '~\b(backline)\b~u',
    'drums'        => '~\b(bateria|drum(?:s)?|kit)\b~u',
    'guitar_amp'   => '~\b(ampli\s*de\s*guitar(?:ra)?|guitar\s*amp)\b~u',
    'bass_amp'     => '~\b(ampli\s*de\s*bai[xs]|bass\s*amp)\b~u',
    'keys'         => '~\b(tech\s*keys|teclat[s]?|keys?|sintes?)\b~u',
    'piano'        => '~\b(piano)\b~u',
    'power'        => '~\b(power|corr?ent|electricitat|schuko|cee)\b~u',
    'risers'       => '~\b(risers?|tarima|tarimes?)\b~u',
    'lights'       => '~\b(llums?|luces?|lighting)\b~u',
    'hospitality'  => '~\b(hospitality|catering|rider\s*(?:hospitality|hospitalari))\b~u',
    'transport'    => '~\b(transport|traslado|van|furgoneta)\b~u',
  ];
  foreach ($map as $label => $rx) {
    if (preg_match($rx, $x)) return $label;
  }
  return null; // ítem desconegut / massa genèric
}

function divisio_items_meta(array $div): array {
  $prom = array_values(array_unique($div['prom']['norm_items'] ?? []));
  $band = array_values(array_unique($div['band']['norm_items'] ?? []));
  return ['prom_items' => $prom, 'band_items' => $band];
}

/* ───────────────────── Bloc de suggeriments (CA/ES + EN curt) ───────────────────── */
function build_suggestion_block(array $rules, array $meta): string {
  $out = [];

  // 1) Data/versió
  if (empty($rules['data_versio'])) {
    $out[] = "• **Data/Versió** — CA: “Versió 1.0 — ".date('Y-m-d')."”.  ES: “Versión 1.0 — ".date('Y-m-d')."”.";
  }

  // 2) Enllaç a repositori
  if (empty($rules['repositori'])) {
    $out[] = "• **Enllaç rider actualitzat** — CA: “Rider actualitzat: https://riders.kinosonik.com/visualitza.php?ref=XXXX”. ES: “Rider actualizado: https://riders.kinosonik.com/visualitza.php?ref=XXXX”.";
  }

  // 3) Tècnic de so (bilingüe + EN curt per FOH/MON)
  $st = $meta['sound_tech'] ?? [];
  if (empty($rules['tecnic_so'])) {
    $out[] = "• **Tècnic de so / Técnico de sonido** —\n"
      ."  CA: “Portem **tècnic de so** (FOH: Band / MON: Band). Si no fos possible, **avisar prèviament**.”\n"
      ."  ES: “Llevamos **técnico de sonido** (FOH: Banda / MON: Banda). Si no fuera posible, **avisar previamente**.”\n"
      ."  EN: “We bring a **sound technician** (FOH: Band / MON: Band). If not possible, **let us know in advance**.”";
  } else {
    // Si ja s’ha detectat format tabulat, reforça amb una línia EN curt
    if (!empty($st['tabular'])) {
      $foh = $st['foh_owner'] ?? '—';
      $mon = $st['mon_owner'] ?? '—';
      $out[] = "• **FOH/MON** — EN: “FOH: {$foh} / MON: {$mon}”.";
    }
  }

  // 4) Divisió d’equip (Promotor/Banda) — només si falta
  $dv = $meta['divisio'] ?? ['prom_items'=>[], 'band_items'=>[]];
  if (empty($rules['equip_divisio'])) {
    $out[] = "• **Divisió d’equip / División de equipo** —\n"
      ."  CA — **Promotor**: PA + sistema FOH, monitors (3–4 falques) o IEM, microfonia estàndard, DI, peus de micro, cablejat i corrent en escenari.\n"
      ."  CA — **Banda**: backline propi (bateria/amps/keys), in-ears personals si s’escau.\n"
      ."  ES — **Promotor**: PA + sistema FOH, monitores (3–4 cuñas) o IEM, microfonía estándar, DI, pies de micro, cableado y corriente en escenario.\n"
      ."  ES — **Banda**: backline propio (batería/amps/teclas), in-ears personales si procede.";
  }

  // 5) Patch: alternatives i suports — només si falta
  if (empty($rules['micro_altern'])) {
    $out[] = "• **Alternatives de micro / Alternativas de micro** —\n"
      ."  CA: “Caixa: SM57 **o** e906 · Bombo: D112 **o** Beta52 · Guitarra: SM57 **o** e906 · Veu: SM58 **o** e935. DI per a keys/pistes. Peus de micro segons posició.”\n"
      ."  ES: “Caja: SM57 **o** e906 · Bombo: D112 **o** Beta52 · Guitarra: SM57 **o** e906 · Voz: SM58 **o** e935. DI para keys/pistas. Pies de micro según posición.”";
  }

  if (!$out) return '';
  array_unshift($out, "—— **Bloc mínim per completar el rider / Bloque mínimo para completar el rider** ——");
  return implode("\n", $out);
}

/* ───────────────────── Patch parsing: extracció d’entrades ───────────────────── */
/**
 * Retorna una llista d'entrades de patch detectades amb camps:
 *  - chan (int)     : número de canal
 *  - desc_ok (bool) : descripció/instrument present
 *  - mic_ok  (bool) : micròfon conegut present
 *  - di_ok   (bool) : interfície/DI/line present
 *  - stand_ok(bool) : suport/peu per mic (si escau)
 *  - notes_ok(bool) : pistes com phantom/eq/gate/comp/…
 */
function parse_patch_entries(string $t): array {
  $lines = preg_split('/\R/u', $t) ?: [];

  // Llista de micros i DI (regex reutilitzable)
  // dins parse_patch_entries(), abans d’usar $micRx:
  $micRx = '~\b('
    .'sm57|sm58|beta\s?58|beta\s?52|d112|e9(?:06|09|35|45)|e604|e904|e935|e945|e965|'
    .'md421|km184|re20|c414|sm7b|i5|m88|m201|d6|d4|d2|kms\s?105|ksm9|beta\s?87a?|'
    .'om7|om5|dpa\s?4099'
    .')\b~iu';
  $diRx   = '/\b(di|reamp|line(?:\s*box)?|hi-?z|jack|minijack|usb\s+audio|xlr\s*out)\b/iu';
  $standRx= '/\b(peu|suport|stand|jirafa|boom|short\s*stand|tall\s*stand|clip|pin[cç]a|clamp)\b/iu';
  $notesRx= '/\b(phantom|48v|eq|gate|compress(or|ió)|comp|hpf|pad|polarity|invert|talkback|click)\b/iu';
  // Mic genèric (sense model): “micro”, “mic”, “inalàmbric/inalámbrico”, “pinça/clip”
  $micGenericRx = '/\b(mic|micro(?:fon[oa]?|phone)?|inal[àa]mbric[oa]?|wireless|pin[çc]a|clip)\b/iu';

  // Instruments + abreviatures típiques de patch
  $srcLex = '/\b('
    .'veu|voz|voice|vocal|vox|ld\s*vox|lead\s*vox|cor(s)?|bvs?|back(?:ing)?\s*vox|'
    .'guitarra|guitar|gtr|egtr|agtr|ac(?:ou?stic)?\s*gtr|el(?:ec(?:tric)?)?\s*gtr|'
    .'baix|bass|bs|'
    .'bombo|kick|bd|'
    .'caixa|snare|sd|'
    .'tom|toms?|t1|t2|t3|rack\s*tom|floor\s*tom|ft|'
    .'overhead|overheads|oh|oh\s*[lr]|oh\s*l|oh\s*r|'
    .'charles|hihat|hh|ride|crash|'
    .'keys?|kbd|keyboard|sintes?|synths?|piano|rhodes|'
    .'pads?|seq|tracks?|playback|laptop|sampler|'
    .'click|talkback'
  .')\b/iu';

  // Accepta: "1  Voz …", "1- Voz …", "CH1: Voz …", "Input 1) Voz …", etc.
  $chanRx = '/^\s*(?:(?:ch(?:\.|an(?:nel)?)?|canal|in(?:put)?)\s*)?'
        .'(\d{1,3})[\h\p{Zs}]*(?:[:\.\-\)]|\||·|•)?[\h\p{Zs}]+(.*)$/u';

  $out = [];
  foreach ($lines as $ln) {
    if (!preg_match($chanRx, $ln, $m)) continue;
    $chan = (int)$m[1];
    $rest = trim((string)$m[2]);
    if ($rest === '') continue;

    // Normalitza separadors típics en taules de patch
    $rest = preg_replace('/[|\t]+/u', ' ', $rest);
    $rest = preg_replace('/\s{2,}/u', ' ', $rest);

    // Si no porta prefix clar, exigeix (desc + mic/di) per evitar falsos positius
    $hasPrefix = preg_match('/^\s*(?:ch(?:\.|#)?|canal|input)\b/iu', $ln);
    $micOK   = (bool)(preg_match($micRx, $rest) || preg_match($micGenericRx, $rest));
    $diOK    = (bool)preg_match($diRx,  $rest);
      // Tracta “ordinador/laptop + targeta de so/audio interface” com a DI implícit
    if (
      !$diOK &&
      preg_match('/\b(ordi(?:nador)?|laptop|port[àa]til)\b/iu', $rest) &&
      preg_match('/\b(targeta\s+de\s+so|audio\s*interface|interf[áà]cie\s+d[eo]\s+[àa]udio)\b/iu', $rest)
    ) {
      $diOK = true;
    }
    $descOK  = (bool)(preg_match($srcLex, $rest) || preg_match('/[a-zà-ÿ0-9]{2,}/iu', $rest));

    if (!$hasPrefix && !($descOK && ($micOK || $diOK))) continue;

    $standOK = (bool)preg_match($standRx, $rest);
    $notesOK = (bool)preg_match($notesRx, $rest);

    $out[] = [
      'chan'     => $chan,
      'desc_ok'  => $descOK,
      'mic_ok'   => $micOK,
      'di_ok'    => $diOK,
      'stand_ok' => $standOK,
      'notes_ok' => $notesOK,
    ];
  }

  // SEGON PASS: si <2 entrades, accepta línies sense número “Voz – KMS105”, “Guitarra - DI”, etc.
  if (count($out) < 2) {
    foreach ($lines as $ln) {
      if (preg_match($chanRx, $ln)) continue;
      $rest = trim($ln);
      if ($rest === '') continue;
      $micOK   = (bool)(preg_match($micRx, $rest) || preg_match($micGenericRx, $rest));
      $diOK    = (bool)preg_match($diRx, $rest);
        if (
        !$diOK &&
        preg_match('/\b(ordi(?:nador)?|laptop|port[àa]til)\b/iu', $rest) &&
        preg_match('/\b(targeta\s+de\s+so|audio\s*interface|interf[áà]cie\s+d[eo]\s+[àa]udio)\b/iu', $rest)
      ) {
        $diOK = true;
      }
      $descOK  = (bool)(preg_match($srcLex, $rest) || preg_match('/[a-zà-ÿ]{3,}/iu', $rest));
      if ($descOK && ($micOK || $diOK)) {
        $standOK = (bool)preg_match($standRx, $rest);
        $notesOK = (bool)preg_match($notesRx, $rest);
        $out[] = [
          'chan'     => null,
          'desc_ok'  => true,
          'mic_ok'   => $micOK,
          'di_ok'    => $diOK,
          'stand_ok' => $standOK,
          'notes_ok' => $notesOK,
        ];
      }
    }
  }

  return $out;
}

/* ───────────────────── micro_altern metrics ───────────────────── */
/**
 * Detecta:
 *  - 'pairs': nombre de línies amb parelles de micros/DI (SM57 o e906, D112/Beta52, …)
 *  - 'has_equiv_word': si apareix “o equivalent/alternativa/similar” en context de patch
 */
function micro_alt_metrics(string $t, array $entries): array {
  // Llista de models comuns + DI
  $micList = '(?:sm57|sm58|beta\s?58|e906|e609|e904|e965|beta\s?52|b52|d112|md421|km184|re20|u87|c414|sm7b|i5|m88|e935|e945|m201|m80|m81|d6|d4|d2|kms\s?105|ksm9|beta\s?87a?|om7|om5|dpa\s?4099|qlxd|axient|ew500|pga|935)';
  $diLex   = '(?:di|reamp|line(?:\s*box)?)';
  // Accepta "/" enganxat i variants sense espai
  $orSep   = '(?:\/\s*|,|;|\s+o\s+|\s+or\s+)';
  $equivRx = '~\b(o\s+equivalent|equivalente|alternati(?:u|va)|similar(?:\s+a)?|any\s+similar|eqv\.?)\b~iu';

  $pairs = 0;
  $hasEquivWord = (bool)preg_match($equivRx, $t);

  foreach ($entries as $e) {
    // reconstruïm la línia original aproximada: el parser ja ens diu si hi havia mic/di
    // però aquí volem parelles explícites (mic-mic, mic-di o di-di) a la mateixa línia
    // Busquem al “rest” original? No el tenim guardat: tornem a extraure la línia crua.
    // Solució: tornem a cercar línies de canal i mirem el "rest".
  }

  // Si no tenim el “rest” guardat, fem un scan de text complet per parelles genèriques:
  // mic-mic
  $pairs += preg_match_all('~\b'.$micList.'\b'.$orSep.'\b'.$micList.'\b~iu', $t, $m1);
  // mic-di o di-mic
  $pairs += preg_match_all('~\b'.$micList.'\b'.$orSep.'\b'.$diLex.'\b~iu', $t, $m2);
  $pairs += preg_match_all('~\b'.$diLex.'\b'.$orSep.'\b'.$micList.'\b~iu', $t, $m3);
  // di-di
  $pairs += preg_match_all('~\b'.$diLex.'\b'.$orSep.'\b'.$diLex.'\b~iu', $t, $m4);

  return ['pairs' => (int)$pairs, 'has_equiv_word' => $hasEquivWord];
}

/* ───────────────────────── Dates / vigència (≤2 anys) ────────────────── */
function dates_meta(string $t): array {
  // Extreu dates/anys i marca “recent” si la darrera és ≤ 730 dies (≈2 anys)
  $dates = [];
  // BOOSTER: any a les primeres línies (títol/capçalera)
  $lines = preg_split('/\R/u', $t) ?: [];
  $head = mb_strtolower(implode(' ', array_slice($lines, 0, 6)), 'UTF-8');
  if (preg_match_all('~\b(20[0-4]\d)\b~u', $head, $headY)) {
    foreach ($headY[1] as $y) {
      $dates[] = sprintf('%04d-01-01', (int)$y);
    }
  }
  // yyyy-mm-dd
  if (preg_match_all('~\b(20[0-4]\d)-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])\b~u', $t, $m, PREG_SET_ORDER)) {
    foreach ($m as $a) $dates[] = "{$a[1]}-{$a[2]}-{$a[3]}";
  }
  // dd/mm/yyyy | dd-mm-yyyy | dd.mm.yyyy
  if (preg_match_all('~\b(0[1-9]|[12]\d|3[01])[\/\-.](0[1-9]|1[0-2])[\/\-.](20[0-4]\d)\b~u', $t, $m, PREG_SET_ORDER)) {
    foreach ($m as $a) $dates[] = "{$a[3]}-{$a[2]}-{$a[1]}";
  }
  // mm/yyyy
  if (preg_match_all('~\b(0[1-9]|1[0-2])[\/\-.](20[0-4]\d)\b~u', $t, $m, PREG_SET_ORDER)) {
    foreach ($m as $a) $dates[] = "{$a[2]}-{$a[1]}-01";
  }
  // anys sols i rangs tipus "2024-25" o "2024/2025"
  preg_match_all('~\b(20[0-4]\d)(?:[-/](\d{2,4}))?\b~u', $t, $ym);
  $years = [];
  if (!empty($ym[0])) {
    foreach ($ym[1] as $i => $y1) {
      $years[] = (int)$y1;
      $tail = $ym[2][$i] ?? '';
      if ($tail !== '' && ctype_digit($tail)) {
        if (strlen($tail) === 2) {
          // ex: 2024-25 → 2025
          $y2 = (int)('20' . $tail);
        } else {
          // ex: 2024-2025
          $y2 = (int)$tail;
        }
        $years[] = $y2;
      }
    }
    $years = array_values(array_unique($years));
  }
  foreach ($years as $y) { $dates[] = sprintf('%04d-01-01', $y); }
  // Noms de mes (CA/ES/EN)
  $months = [
    'gener'=>'01','febrer'=>'02','març'=>'03','abril'=>'04','maig'=>'05','juny'=>'06','juliol'=>'07','agost'=>'08','setembre'=>'09','octubre'=>'10','novembre'=>'11','desembre'=>'12',
    'ene(?:ro)?'=>'01','feb(?:rero)?'=>'02','mar(?:zo)?'=>'03','abr(?:il)?'=>'04','mayo'=>'05','jun(?:io)?'=>'06','jul(?:io)?'=>'07','ago(?:sto)?'=>'08','sep(?:tiembre)?'=>'09','oct(?:ubre)?'=>'10','nov(?:iembre)?'=>'11','dic(?:iembre)?'=>'12',
    'jan(?:uary)?'=>'01','feb(?:ruary)?'=>'02','mar(?:ch)?'=>'03','apr(?:il)?'=>'04','may'=>'05','jun(?:e)?'=>'06','jul(?:y)?'=>'07','aug(?:ust)?'=>'08','sep(?:tember)?'=>'09','oct(?:ober)?'=>'10','nov(?:ember)?'=>'11','dec(?:ember)?'=>'12',
  ];
  // "10 d’octubre de 2025" / "10 Oct 2025"
  if (preg_match_all('~\b(0?[1-9]|[12]\d|3[01])\s*(?:d[\'’]|\s+de\s+)?\s*([A-Za-zÀ-ÿ\.]+)\s*(?:de\s+)?\s*(20[0-4]\d)\b~u', $t, $m, PREG_SET_ORDER)) {
    foreach ($m as $a) {
      $d = str_pad($a[1], 2, '0', STR_PAD_LEFT);
      $mon = mb_strtolower($a[2], 'UTF-8');
      foreach ($months as $rx=>$mm) { if (preg_match('~^'.$rx.'$~u', $mon)) { $dates[] = "{$a[3]}-{$mm}-{$d}"; break; } }
    }
  }
  // "octubre 2025" / "Oct 2025"
  if (preg_match_all('~\b([A-Za-zÀ-ÿ\.]+)\s*(?:de\s+)?\s*(20[0-4]\d)\b~u', $t, $m, PREG_SET_ORDER)) {
    foreach ($m as $a) {
      $mon = mb_strtolower($a[1], 'UTF-8');
      foreach ($months as $rx=>$mm) { if (preg_match('~^'.$rx.'$~u', $mon)) { $dates[] = "{$a[2]}-{$mm}-01"; break; } }
    }
  }
  // Tria la més recent
  $latestTs = null; $latestStr = null;
  foreach ($dates as $ds) {
    $ts = strtotime($ds); if ($ts === false) continue;
    if ($latestTs === null || $ts > $latestTs) { $latestTs = $ts; $latestStr = date('Y-m-d', $ts); }
  }
  $curTs = time();
  $isRecent = false; $ageYears = null; $latestYear = null;
  if ($latestTs !== null) {
    $diffDays = (int)floor(($curTs - $latestTs) / 86400);
    $isRecent = ($diffDays <= 730);
    $latestYear = (int)date('Y', $latestTs);
    $ageYears = max(0, (int)date('Y') - $latestYear);
  }
  $hasVer = (bool)preg_match('~\b(v(?:er(?:sion)?)?\.?\s*\d+(?:\.\d+){0,2})\b~iu', $t);
  return [
    'years'        => $years,
    'latest_year'  => $latestYear,
    'latest_date'  => $latestStr,
    'age_years'    => $ageYears,
    'is_recent'    => $isRecent,
    'has_version'  => $hasVer,
  ];
}

function build_compact_block(array $opts = []): string {
  // opcions amb valors per defecte
  $ref   = (string)($opts['ref']   ?? '<codi>');
  $host  = (string)($opts['host']  ?? 'riders.kinosonik.com');
  $vstr  = (string)($opts['versio'] ?? ('Versió 1.0 ('.date('m/Y').')'));

  // línies opcionals segons mancances
  $t_so  = (bool)($opts['need_tecnic_so'] ?? false)
           ? "TÈCNIC DE SO — Portem tècnic propi (FOH) i tècnic de sala per MON.\n"
           : "";
  $div   = (bool)($opts['need_divisio'] ?? false)
           ? "EQUIP:\nPromotor → PA, FOH desk, monitors, cablejat.\nBanda → backline, in-ears, micros específics.\n"
           : "";
  $mic   = (bool)($opts['need_micro_alt'] ?? false)
           ? "PATCH: SM58 o e935 / SM57 o e906 / D112 o Beta52 (equivalent)\n"
           : "";

  $lines = [
    $vstr,
    "https://{$host}/visualitza.php?ref={$ref}",
    $t_so !== "" ? "\n{$t_so}" : "",
    $div  !== "" ? "\n{$div}"  : "",
    $mic  !== "" ? "\n{$mic}"  : "",
  ];
  // neteja blocs buits i dobles salts
  $txt = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", array_filter($lines, fn($x)=>$x!==""))));
  return $txt;
}


/* ───────────────────────── CLI (debug) ─────────────────────────
 * Executa:
 *   php php/ia_extract_heuristics.php --file=/path/al/rider.pdf
 * Requereix que hagis extret text prèviament o disposis d’un extract_text().
 */
if (PHP_SAPI === 'cli' && basename(__FILE__) === basename($_SERVER['argv'][0] ?? '')) {
  $args = implode(' ', array_slice($_SERVER['argv'], 1));
  if (!preg_match('/--file=(?<f>.+\.pdf)/i', $args, $m)) {
    fwrite(STDERR, "Usage: php ia_extract_heuristics.php --file=/abs/path/file.pdf\n");
    exit(1);
  }
  $pdfPath = (string)$m['f'];
  if (!is_readable($pdfPath)) { fwrite(STDERR, "No es pot llegir: $pdfPath\n"); exit(2); }

  // Placeholder: si tens una funció global extract_text(), usa-la; si no, dummy.
  if (function_exists('extract_text')) {
    $txt = (string)extract_text($pdfPath);
  } else {
    $txt = ""; // demà lliguem amb pdftotext / Poppler
  }

  $res = run_heuristics($txt, $pdfPath, [
    // passa-hi metadades si les tens (p. ex. ref/host/versió)
    // 'ref' => 'ABC123',
    // 'host' => 'riders.kinosonik.com',
    // 'versio' => '1.0',
  ]);
  echo json_encode($res, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
/**
 * ia_extract_heuristics — façana per la demo i la IA local
 */
function ia_extract_heuristics(string $text, array $opts = []): array {
  // Crida el pipeline complet de heurístiques
  $res = run_heuristics($text, '', $opts);

  // Normalitza resultat per la demo
  return [
    'score' => (int)round($res['score'] ?? 0),
    'flags' => $res['flags'] ?? [],
    'label' => $res['label'] ?? 'Rider'
  ];
}
