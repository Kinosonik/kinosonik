<style>
/* HERO Productor Tècnic PRO */
.pro-hero { 
  position: relative;
  min-height: 420px;
}
@media (min-width: 768px) {
  .pro-hero { min-height: 560px; }
}
.pro-hero img {
  width: 100%;
  height: 100%;
  display: block;
  object-fit: cover;
}

/* Ombra per llegibilitat (sota el text) */
.pro-hero-shade {
  position: absolute;
  inset: 0;
  background: linear-gradient(180deg, rgba(0,0,0,.6), rgba(0,0,0,.25) 50%, rgba(0,0,0,.6));
  z-index: 1;
  pointer-events: none;
}

/* Text a dalt, centrat horitzontal */
.pro-hero-copy{
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  justify-content: flex-start;   /* enganxat a dalt */
  align-items: center;           /* centrat horitzontal */
  text-align: center;
  padding: clamp(16px, 6vh, 64px) 1rem 2rem;
  z-index: 2;                    /* per sobre de l'ombra */
}

/* Amplada i color del text (força blanc per sobre de .text-black si cal) */
.pro-hero-copy h2,
.pro-hero-copy p {
  max-width: 900px;
  width: 92%;
  color: #fff !important;
  margin-bottom: .5rem;
}
.pro-hero-copy p { margin-bottom: 0; }


</style>

<section id="ProductorTecnic" class="container-fluid bg-black text-body py-6 pb-0 mb-0" aria-labelledby="proTitle">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-10 col-xl-9">
        <!-- Hero imatge amb text dins TÍTOL + PARÀGRAF -->
        <figure class="pro-hero position-relative rounded-4 overflow-hidden">
          <img
            src="/img/section/fons_pro.jpg"
            class="w-100 d-block"
            alt="Vista del flux de riders per a productor tècnic"
            loading="lazy"
          />
          <!-- ENFOSQUIMENT IMATGE -->
          <div class="pro-hero-shade"></div>
          <figcaption class="pro-hero-copy text-white text-center px-3 px-md-4">
            <h2 id="proTitle" class="display-4 fw-bold lh-1 mb-3 text-gradient">Productor Tècnic PRO</h2>
            <p class="lead mb-0">
              Centralitza els riders originals, crea contra-riders per escenari i tanca riders finals amb traçabilitat completa.
              IA precheck automàtic per riders sense segell i packs compartibles per escenari o producció.
            </p>
          </figcaption>
        </figure>

        <!-- 3 punts clau -->
        <!-- PUNT 1 -->
        <div class="row text-center g-4">
          <div class="col-12 col-md-4">
            <p class="h3 fw-bold mb-1">Recopila riders</p>
            <p class="text-secondary mb-0">Crea un festival amb diferents escenaris i dates, i associa-hi els riders de cada actuació. Obté informació
              tècnica vàlida amb la IA.
            </p>
          </div>
          <!-- PUNT 2 -->
          <div class="col-12 col-md-4">
            <p class="h3 fw-bold mb-1">Contra-rider</p>
            <p class="text-secondary mb-0">Crea i publica el teu contra-rider, fes un seguiment
              exhaustiu amb historial a temps real amb els riders de cada actuació i escenari. Tot sempre sota control
              i amb integració absoluta amb Kinosonik Riders.
            </p>
          </div>
          <!-- PUNT 3 -->
          <div class="col-12 col-md-4">
            <p class="h3 fw-bold mb-1">Packs finals</p>
            <p class="text-secondary mb-0">Signa i verifica la recepció i comprensió del contra-rider final. Publica i facilita
              tota la informació en packs per festival, escenaris o actuacions; amb control d'accés integrat.
            </p>
          </div>
        </div>

        <!-- CTA (mateixa línia) -->
        <div class="d-flex justify-content-center gap-3 mt-5">
          <button type="button" class="btn btn-primary px-4" data-bs-toggle="modal" data-bs-target="#registre_usuaris">
            <?= __('index.comenca') ?>
          </button>
        </div>

      </div>
    </div>
  </div>
</section>