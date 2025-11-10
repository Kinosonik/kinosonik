<!-- parts/demo_ia.php — Bloc demo heurística -->
<section id="test-ia" class="container-fluid py-6" style="background-color:#181a1c;">
  <div class="container">
    <h2 class="display-4 fw-bold mb-3 text-gradient text-center">Prova la IA del teu rider, gratuïtament.</h2>
    <p class="lead text-secondary mb-5 text-center">Arrossega o selecciona el rider i obtén una valoració immediata</p>

    <div class="row justify-content-center">
        <div class="col-8 col-lg-6">
            <div class="card border-1 shadow liquid-glass-kinosonik">
                <!-- Body card -->
                <div class="card-body">
                    <!-- Missatge error -->
                     <div id="demoError" class="w-100 mb-3 mt-3 small text-danger d-none"
                        style="background:rgba(255, 7, 7, 0.15);
                        border-left:3px solid #ff0707ff;
                        padding:25px 18px;">
                    </div>
                    <!-- Formulari pujada rider -->
                    <div class="small mt-2">
                        <form id="demoForm" class="row g-3" enctype="multipart/form-data">
                            <div class="col-md-1"></div>     
                            <div class="col-md-10">
                                <label class="form-label text-secondary" for="nom">Puja el teu rider en PDF (màx. 20 MB)</label>
                                <input type="file" id="demoFile" name="file" accept="application/pdf" class="form-control mb-3" required>
                            </div>
                            <div class="col-12 text-center">
                                <button type="submit" id="btnDemo" class="btn btn-primary" disabled>
                                    <i class="bi bi-robot"></i> Analitza ara
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
  </div>
</section>

<!-- Modal resultat IA -->
<div class="modal fade" id="demoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-dark text-light border border-secondary border-opacity-50">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-robot"></i> Resultat de l'anàlisi</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tanca"></button>
      </div>
      <div class="modal-body text-center" id="demoModalBody">
        <div id="demoSpinner">
          <div class="spinner-border text-primary mb-3" role="status"></div>
          <p class="mb-0 small">Analitzant el teu rider...</p>
        </div>
      </div>
      <div class="modal-footer justify-content-center">
        <a href="signup.php" class="btn btn-outline-primary btn-sm">Crea un compte i coneix Kayro</a>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const demoFile = document.getElementById('demoFile');
  const btnDemo = document.getElementById('btnDemo');
  const demoForm = document.getElementById('demoForm');
  const demoModal = new bootstrap.Modal(document.getElementById('demoModal'));
  const demoModalBody = document.getElementById('demoModalBody');
  const demoError = document.getElementById('demoError');

  demoFile.addEventListener('change', (e) => {
    const file = e.target.files[0];
    btnDemo.disabled = !(file && file.type === 'application/pdf');
  });

  demoForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    demoError.classList.add('d-none');
    demoModal.show();
    demoModalBody.innerHTML = document.getElementById('demoSpinner').outerHTML;

    const formData = new FormData();
    formData.append('file', demoFile.files[0]);

    try {
      const res = await fetch('/php/ia_demo.php', { method: 'POST', body: formData });
      const data = await res.json();
      if (!res.ok || data.error) throw new Error(data.error || 'Error');

      const color = data.score > 80 ? 'success' : data.score >= 60 ? 'warning' : 'danger';
      demoModalBody.innerHTML = `
        <h5 class="mb-3 text-${color}">${data.label}</h5>
        <div class="progress mb-3" style="height:20px;">
          <div class="progress-bar bg-${color}" style="width:${data.score}%">${data.score}</div>
        </div>
        <p class="small text-body-secondary">Versió heurística local (sense IA externa)</p>
      `;
    } catch (err) {
      demoModal.hide();
      demoError.textContent = 'Error en l\'anàlisi: ' + err.message;
      demoError.classList.remove('d-none');
    }
  });
});
</script>

