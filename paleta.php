<?php
/* ── PHP mínim inicial  ──────────────────────────────────────────── */
declare(strict_types=1);
require_once __DIR__ . '/php/preload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/php/i18n.php';
require_once __DIR__ . '/php/middleware.php';

/* ── Head + Nav ──────────────────────────────────────────── */
require_once __DIR__ . '/parts/head.php';
require_once __DIR__ . '/parts/navmenu.php';
/* ── <body> i <main> ja estan aplicats ── */
?>

<div class="container my-3"> <!-- Container inicial, de w-75 per la majoria de casos -->
    
    <!-- Títol Secció -->
    <div class="d-flex justify-content-between align-items-center mb-1">
        <h4 class="border-bottom border-1 border-secondary pb-2 w-100">
        <i class="bi bi-apple"></i>&nbsp;&nbsp;
        Títol secció
        </h4>
    </div>

    <!-- Botons sota títol -->
    <div class="btn-group mb-4" role="group" aria-label="">
        <button 
            type="button" 
            class="btn btn-primary btn-sm"    
            onclick="window.location.href='index.php';">
            <i class="bi bi-1-circle"></i> Botó 1
        </button>
        <button 
            type="button" 
            class="btn btn-primary btn-sm"    
            onclick="window.location.href='index.php';">
            <i class="bi bi-2-circle"></i> Botó 2
        </button>
        <button 
            type="button" 
            class="btn btn-primary btn-sm"    
            onclick="window.location.href='index.php';">
            <i class="bi bi-3-circle"></i> Botó 3
        </button>
    </div>
        
    <!-- Llistat informatiu sota títol -->
    <div class="d-flex mb-1 small text-secondary">
        <div class="w-100">
            <div class="row row-cols-auto justify-content-start text-start">
                <div class="col">
                Descripció 1: 
                <span class="text-light">Allò que descriu</span>&nbsp;
                <a target="_blank"
                    href="">
                    <i class="bi bi-question-circle"></i>
                </a>
                </div>
                <div class="col">
                Descripció 2:
                <span class="text-light">Allò que descriu 2</span>
                </div>
                <div class="col">Descripció 3: <span class="text-light">Allò que descriu 3</span></div>
            </div>
        </div>
    </div>

    <!-- Títol avís permanent -->
    <div class="w-100 mb-3 mt-3 small text-warning"
        style="background:rgba(255,193,7,0.15);
        border-left:3px solid #ffc107;
        padding:25px 18px;">
        <strong class="text-warning">Títol </strong>avís.
    </div>

    <!-- Títol info dins de caixa permanent -->
    <div class="small">
        <div class="w-100 mb-4 mt-2 small text-light"
            style="background: var(--ks-veil);
            border-left:3px solid var(--ks-accent);
            padding:12px 18px;">
            <strong class="text-secondary">Event </strong>EVENT · <strong class="text-secondary">Dates </strong>EVENT 
        </div>
    </div>

    <!-- Breadcrumb w-100 -->
    <div class="w-100 mb-2 mt-2 small bc-kinosonik">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li><a href="#">Home</a></li>
                <li><a href="#">Library</a></li>
                <li class="active" aria-current="page">Data</li>
            </ol>
        </nav>
    </div>

    <!-- Taula normal w75 -->
    <div class="table-responsive">
        <h5 class="mt-3 mb-2">Llistat d’actuacions (títol gran sobre taula)</h5>
        <table class="table table-sm align-middle">
            <caption class="caption-top text-light">Llistat d’actuacions (títol més petit sobre taula)</caption>
            <thead class="table-dark">
                <tr>
                    <th class="text-center text-light">C1</th>
                    <th class="text-start text-light">Columna 2</th>
                    <th class="text-start text-light">Columna 3</th>
                    <th class="text-center text-light">Columna 4</th>
                    <th class="text-start text-light">Accions</th>
                </tr>
            </thead>
            <tbody>
                <!-- NO HI HA RESULTATS -->
                <tr><td colspan="5" class="text-center text-body-secondary py-2">— <?= h(__('no_results') ?? 'Sense resultats') ?> —</td></tr>
                <!-- Fi no hi ha resultats // EndIf i Foreach -->
                <tr>
                    <td class="text-center">999</td>
                    <td class="text-truncate">Resultats C2</td>
                    <td class="text-start">Resultats C3</td>
                    <td class="text-center">Resultats C4</td>
                    <td class="text-end">
                        <!-- Grup de botons -->
                         <div class="btn-group me-2 flex-nowrap" role="group">
                                <button 
                                    type="button" 
                                    class="btn btn-primary btn-sm"    
                                    onclick="window.location.href='index.php';">
                                    <i class="bi bi-1-circle"></i>
                                </button>
                                <button 
                                    type="button" 
                                    class="btn btn-primary btn-sm"    
                                    onclick="window.location.href='index.php';">
                                    <i class="bi bi-2-circle"></i>
                                </button>
                                <button 
                                    type="button" 
                                    class="btn btn-primary btn-sm"    
                                    onclick="window.location.href='index.php';">
                                    <i class="bi bi-3-circle"></i>
                                </button>
                        </div>
                    </td>
                </tr>
                <!-- ENDFOREACH -->
            </tbody>
        </table>
    </div>

    <!-- CAIXES DE TAULA -->
    <div class="card border-1 shadow-sm">
        <div class="card-header bg-kinosonik esquerra">
            <h6>Títol caixa de taula (.esquerra o .centered)</h6>
            <!-- Botó de tancar caixa -->
            <div class="btn-group ms-2">
                <button type="button"
                    class="btn-close"
                    aria-label="<?= h(__('common.close') ?: 'Tanca') ?>"
                    onclick="this.closest('.card')?.remove();">
                </button>
            </div>
        </div>
        <!-- Contingut dins de la caixa -->
        <div class="card-body">
            <div class="row justify-content-md-center mb-2">
                <div class="col-8">
                    <label class="form-label small">
                        Descripció 1
                        <span class="form-text">Descripció precisa</span>
                    </label>
                    <input type="text" name="" class="form-control form-control-sm" required>
                </div>
                <div class="col-4">
                    <label class="form-label small">
                        Descripció 2
                        <span class="form-text">Descripció precisa</span>
                    </label>
                    <input type="text" name="" class="form-control form-control-sm">
                </div>
            </div>
            <div class="row mb-2">
                <div class="col-md-8">
                    <label class="form-label small">Descripció 3<span class="form-text">Descripció precisa</span></label>
                    <input type="file" id="" name="rider_pdf" accept="application/pdf,.pdf" class="form-control form-control-sm" required>
                </div>
                <!-- Botó de pujar rider -->
                <div class="col-md-4 d-flex align-items-end justify-content-end gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-apple me-1"></i>
                        Botó
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Repositori de COLORS Bootstrap -->
    <h5 class="mt-3 mb-2">Repositori de Color Bootstrap</h5>
    <ul class="list-inline small mb-4">
        <li class="list-inline-item text-primary">Text primary</li>
        <li class="list-inline-item text-secondary">Text secondary</li>
        <li class="list-inline-item text-success">Text success</li>
        <li class="list-inline-item text-danger">Text danger</li>
        <li class="list-inline-item text-warning">Text warning</li>
        <li class="list-inline-item text-info">Text info</li>
        <li class="list-inline-item text-light">Text light</li>
        <li class="list-inline-item text-dark">Text dark</li>
    </ul>

    <!-- Repositori de icones Bootstrap -->
    <h5 class="mt-3 mb-3">Repositori d'icones Bootstrap</h5>
    <ul id="icons-list" class="row row-cols-3 row-cols-sm-4 row-cols-lg-6 row-cols-xl-8 list-unstyled list"> 
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-robot"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">robot</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Motor IA</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-rocket-takeoff"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">bi-rocket-takeoff</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Riders vistos</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-trash3"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">trash-3</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Papereres</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-pencil-square"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">pencil-square</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Editar (?)</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-heart"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">heart</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Cor footer animat</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-arrow-right-circle"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">arrow-right-circle</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Eines admin (1)</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-arrow-down-circle"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">arrow-down-circle</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Eines admin (2)</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-journal-text"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">journal-text</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Auditoria</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-bar-chart"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">bar-chart</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">KIPs</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-filetype-csv"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">filetype-csv</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">CSV</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-filetype-json"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">filetype-json</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">JSON</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-filetype-pdf"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">filetype-pdf</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">PDF</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-archive"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">archive</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Riders</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-gear-wide-connected"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">gear-wide-connected</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Producció tècnica</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-person-circle"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">person-circle</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Dades personal</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-question-circle"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">question-circle</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Ajuda</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-arrow-down-up"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">arrow-down-up</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Ordenar columnes</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-arrow-up-short"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">arrow-up-short</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Ordenat amunt</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-arrow-down-short"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">arrow-down-short</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Ordenat avall</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-arrow-left"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">arrow-left</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Tornar</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-clock"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">clock</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Dates informatiu</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-x"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">x</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Perill</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-x-circle"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">x-circle</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Perill</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-eye"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">eye</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Perill</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-check-circle-fill"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">check-circle-fill</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Ok</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-check-circle"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">bi-check-circle</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Ok</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-search"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">search</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Buscar</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-inboxes"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">inboxes</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">???</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-play-circle-fill"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">play-circle-fill</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Fent-se</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-person-badge"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">person-badge</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">???</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-file-text"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">file-text</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">???</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-shield-lock"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">bi-shield-lock</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Escut tancat</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-exclamation-circle"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">exclamation-circle</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Atenció</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-clock-history"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">clock-history</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Es va fent...</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-plus-circle"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">bi-plus-circle</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Afegir</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-upload"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">upload</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Upload/Pujar</div>
          </div>
      </li>
      <li class="col mb-4">
          <div class="px-3 py-4 mb-2 bg-body-secondary text-center rounded text-light" style="font-size:1.75em;">
            <i class="bi bi-bell"></i>
            <div class="name text-light text-decoration-none text-center pt-1" style="font-size: 0.6em;">bell</div>
            <div class="name text-muted text-decoration-none text-center pt-1 text-light" style="font-size: 0.6em;">Subsripció a rider</div>
          </div>
      </li>
    </ul>

</div><!-- FORA DEL DIV W75 -->

<hr>

<!-- MODALS -->

<!-- Crida modal -->
<div class="container">
    <div class="w-100 text-center">
        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#exampleModal">
            <i class="bi bi-trash me-1"></i> Obrir modal de PERILL
        </button>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#exampleInfo">
            <i class="bi bi-info-circle me-1"></i> Obrir modal d'informació'
        </button>
    </div>
</div>
<!-- HTML Modal exampleModal -->
<div class="modal fade" id="exampleModal" tabindex="-1" aria-labelledby="modalDeleteEventLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-light border border-danger-subtle">
            <div class="modal-header border-0">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-circle me-2 text-danger"></i>Títol modal
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tanca"></button>
            </div>

            <div class="modal-body">
                <p class="mb-2 small">
                    Segur que vols eliminar aquest contingut?
                </p>
                <div class="border rounded mb-4 p-2 bg-kinosonik small">
                    <div class="row">
                        <div class="col-4 text-muted">Definció 1</div>
                        <div class="col-8">Contingut 1</div>
                    </div>
                    <div class="row">
                        <div class="col-4 text-muted">Definició 2</div>
                        <div class="col-8">Contignut 2</div>
                    </div>
                </div>
                <p class="mb-2 small">
                    Aquesta acció és <strong>definitiva</strong> i esborrarà:
                </p>
                <ul class="small mb-3">
                    <li>Esborra 1</li>
                    <li>Esborra 2</li>
                    <li>Esborra 3</li>
                </ul>
                <hr>
                <div class="mt-3 small mb-4">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input del-req" type="checkbox" id="del-ck-irreversible">
                        <label class="form-check-label" for="del-ck-irreversible">
                            Entenc que aquesta acció és irreversible.
                        </label>
                    </div>
                </div>
                <hr>
                <label for="confirmWord" class="form-label small text-secondary small">Escriu <code>ELIMINA</code> per confirmar:</label>
                <input type="text" class="form-control" id="confirmWord" placeholder="ELIMINA" autocomplete="off">
            </div>

            <div class="modal-footer border-0">
                <form method="post" action="" id="" class="ms-auto">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?= h(__('common.cancel') ?: 'Cancel·la') ?></button>
                    <button type="submit" class="btn btn-sm btn-danger" id="btnConfirmDelete" disabled>
                        <i class="bi bi-trash me-1"></i> <?= h(__('common.delete') ?: 'Elimina') ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<!-- Fi modal -->

<!-- HTML Modal info -->
<div class="modal fade" id="exampleInfo" tabindex="-1" aria-labelledby="" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-light border border-primary-subtle">
            <div class="modal-header border-bottom border-primary-subtle centered">
                <h5 class="modal-title text-center">
                    <i class="bi bi-plus-circle me-2 text-primary"></i>Títol modal informatiu
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tanca"></button>
            </div>

            <div class="modal-body small">
                <p class="mb-2">
                    Contingut
                </p>
            </div>

            <div class="modal-footer border-0">
                <form method="post" action="" id="" class="ms-auto">
                    <button class="btn btn-sm btn-primary" type="submit">
                        <i class="bi bi-plus-circle me-1"></i> <?= h(__('common.save') ?: 'Desa') ?>
                    </button>
                    <button class="btn btn-sm btn-secondary" type="reset" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> <?= h(__('common.cancel') ?: 'Cancela') ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<!-- Fi modal -->

<hr>



<!-- FORMULARIS RÀPIDS -->
<div class="container">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <div class="card border-1 shadow">
                <!-- Títol box -->
                <div class="card-header bg-kinosonik centered">
                    <h6>Títol caixa de taula (.esquerra o .centered)</h6>
                    <div class="btn-group ms-2">
                        <a class="btn-close btn-close-white" href="#"></a>
                    </div>
                </div>
                <!-- Body card -->
                <div class="card-body">
                    <!-- Títol info dins de caixa permanent -->
                    <div class="small">
                        <div class="w-100 mb-4 mt-2 small text-light"
                            style="background: var(--ks-veil);
                            border-left:3px solid var(--ks-accent);
                            padding:12px 18px;">
                        <strong class="text-secondary">Event </strong>EVENT · <strong class="text-secondary">Dates </strong>EVENT 
                        </div>
                    </div>
                    <div class="small">
                        <form method="post" action="" class="row g-3" novalidate>
                            <div class="col-md-8">
                                <label class="form-label" for="nom">Etiqueta 1</label>
                                <input type="text" id="nom" name="nom" maxlength="180" required class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="estat">Etiqueta 2</label>
                                <select id="estat" name="estat" class="form-select">
                                    <option value="esborrany">esborrany</option>
                                    <option value="actiu">actiu</option>
                                    <option value="tancat">tancat</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1" id="chkOpen" name="is_open_ended">
                                    <label class="form-check-label" for="chkOpen">Etiqueta</label>
                                </div>
                            </div>
                            <div class="col-12 text-end">
                                <button class="btn btn-sm btn-primary" type="submit">
                                    <i class="bi bi-plus-circle me-1"></i> <?= h(__('common.save') ?: 'Desa') ?>
                                </button>
                                <button class="btn btn-sm btn-secondary" type="reset">
                                    <i class="bi bi-x-circle me-1"></i> <?= h(__('common.reset') ?: 'Neteja') ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<hr>

<!-- TAULA W-100 FORA DEL <div w75> ------>
<div class="container-fluid my-0 pt-3">
    <div class="card-body">
        <h5 class="mt-3 mb-2">Llistat d’actuacions (títol gran sobre taula)</h5>
        <div class="table-responsive-md overflow-visible">
            <table class="table table-sm align-middle">
                <caption class="caption-top text-light">Llistat d’actuacions (títol més petit sobre taula)</caption>
                <thead class="table-dark">
                    <tr>
                        <th class="text-center text-light">C1</th>
                        <th class="text-start text-light">Columna 2</th>
                        <th class="text-start text-light">Columna 3</th>
                        <th class="text-center text-light">Columna 4</th>
                        <th class="text-start text-light">Accions</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- NO HI HA RESULTATS -->
                    <tr><td colspan="5" class="text-center text-body-secondary py-2">— <?= h(t('no_results') ?? 'Sense resultats') ?> —</td></tr>
                    <!-- Fi no hi ha resultats // EndIf i Foreach -->
                    <tr>
                        <td class="text-center">999</td>
                        <td class="text-truncate">Resultats C2</td>
                        <td class="text-start">Resultats C3</td>
                        <td class="text-center">Resultats C4</td>
                        <td class="text-end">
                            <!-- Grup de botons -->
                            <div class="btn-group me-2 flex-nowrap" role="group">
                                    <button 
                                        type="button" 
                                        class="btn btn-primary btn-sm"    
                                        onclick="window.location.href='index.php';">
                                        <i class="bi bi-1-circle"></i>
                                    </button>
                                    <button 
                                        type="button" 
                                        class="btn btn-primary btn-sm"    
                                        onclick="window.location.href='index.php';">
                                        <i class="bi bi-2-circle"></i>
                                    </button>
                                    <button 
                                        type="button" 
                                        class="btn btn-primary btn-sm"    
                                        onclick="window.location.href='index.php';">
                                        <i class="bi bi-3-circle"></i>
                                    </button>
                            </div>
                        </td>
                    </tr>
                    <!-- ENDFOREACH -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
/* ── Footer ──────────────────────────────────────────── */
require_once __DIR__ . '/parts/footer.php';
?>