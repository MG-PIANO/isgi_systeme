<?php
/**
 * ISGI - Studio Photo Mobile pour Badges & Cartes d'Étudiants
 * Accessible depuis Smartphone / Tablette / Navigateur
 */
$matricule_param = $_GET['matricule'] ?? '';
$session_id = $_GET['session'] ?? ($_GET['session_id'] ?? '');
$nom_param = $_GET['nom'] ?? '';
$prenom_param = $_GET['prenom'] ?? '';
$classe_param = $_GET['classe'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>ISGI — Studio Photo Mobile</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #003087;
      --primary-light: #1A56DB;
      --secondary: #FF6B00;
      --bg: #0F172A;
      --surface: #1E293B;
      --surface-light: #334155;
      --border: #475569;
      --text: #F8FAFC;
      --text-muted: #94A3B8;
      --success: #10B981;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; -webkit-tap-highlight-color: transparent; }

    body {
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      padding-bottom: 24px;
    }

    /* Top Bar */
    header {
      background: var(--surface);
      border-bottom: 1px solid var(--border);
      padding: 12px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 40;
    }
    .brand {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .brand img {
      width: 36px;
      height: 36px;
      border-radius: 8px;
      object-fit: cover;
      background: white;
      padding: 2px;
    }
    .brand-title {
      font-size: 15px;
      font-weight: 700;
      color: white;
      line-height: 1.2;
    }
    .brand-sub {
      font-size: 11px;
      color: var(--secondary);
      font-weight: 600;
    }

    main {
      flex: 1;
      padding: 16px;
      max-width: 500px;
      margin: 0 auto;
      width: 100%;
    }

    /* Student info card */
    .student-card {
      background: linear-gradient(135deg, var(--surface) 0%, rgba(0, 48, 135, 0.4) 100%);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 16px;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .student-avatar-badge {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      background: var(--primary);
      color: white;
      font-weight: 800;
      font-size: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 2px solid var(--secondary);
      flex-shrink: 0;
    }
    .student-meta {
      flex: 1;
      min-width: 0;
    }
    .student-name {
      font-size: 16px;
      font-weight: 700;
      color: white;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .student-mat {
      font-size: 13px;
      font-weight: 600;
      color: var(--secondary);
      margin-top: 2px;
    }
    .student-class {
      font-size: 12px;
      color: var(--text-muted);
      margin-top: 1px;
    }

    /* Search bar (if no student selected) */
    .search-box {
      margin-bottom: 16px;
      position: relative;
    }
    .search-input {
      width: 100%;
      padding: 12px 14px 12px 40px;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 12px;
      color: white;
      font-size: 14px;
      outline: none;
    }
    .search-input:focus {
      border-color: var(--primary-light);
    }
    .search-icon {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
    }
    .student-search-results {
      max-height: 220px;
      overflow-y: auto;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 12px;
      margin-top: 6px;
      display: none;
    }
    .result-item {
      padding: 10px 14px;
      border-bottom: 1px solid rgba(255,255,255,0.06);
      display: flex;
      align-items: center;
      justify-content: space-between;
      cursor: pointer;
    }
    .result-item:active {
      background: var(--surface-light);
    }

    /* Camera Viewport Area */
    .camera-container {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 20px;
      overflow: hidden;
      position: relative;
      aspect-ratio: 3 / 4;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 10px 30px rgba(0,0,0,0.5);
    }

    /* Guide overlay (face oval for ID card) */
    .face-guide {
      position: absolute;
      top: 12%;
      left: 50%;
      transform: translateX(-50%);
      width: 62%;
      height: 60%;
      border: 2px dashed rgba(255, 107, 0, 0.85);
      border-radius: 50% 50% 48% 48% / 55% 55% 45% 45%;
      pointer-events: none;
      box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.45);
      z-index: 10;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: flex-end;
      padding-bottom: 12px;
    }
    .face-guide span {
      background: rgba(0,0,0,0.6);
      color: white;
      font-size: 11px;
      padding: 4px 8px;
      border-radius: 20px;
      font-weight: 600;
    }

    .preview-canvas {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .placeholder-state {
      text-align: center;
      padding: 24px;
      color: var(--text-muted);
    }
    .placeholder-state svg {
      width: 56px;
      height: 56px;
      color: var(--secondary);
      margin-bottom: 12px;
      opacity: 0.8;
    }

    /* Controls Bar */
    .action-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-top: 14px;
    }
    .btn {
      padding: 13px 16px;
      border-radius: 12px;
      font-size: 14px;
      font-weight: 700;
      border: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: all 0.2s;
    }
    .btn:active { transform: scale(0.98); }
    .btn-camera {
      background: var(--secondary);
      color: white;
    }
    .btn-gallery {
      background: var(--surface-light);
      color: white;
    }
    .btn-save {
      background: var(--success);
      color: white;
      width: 100%;
      margin-top: 12px;
      padding: 15px;
      font-size: 16px;
    }
    .btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    /* Adjustment tools panel */
    .adjust-panel {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 14px;
      margin-top: 14px;
    }
    .adjust-title {
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      color: var(--text-muted);
      margin-bottom: 10px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .adjust-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    .adjust-item label {
      display: block;
      font-size: 11px;
      color: var(--text-muted);
      margin-bottom: 4px;
    }
    .adjust-item input[type="range"] {
      width: 100%;
      accent-color: var(--secondary);
    }
    .rotate-btn {
      width: 100%;
      padding: 8px;
      background: var(--surface-light);
      color: white;
      border-radius: 8px;
      border: none;
      font-size: 12px;
      font-weight: 600;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      cursor: pointer;
    }

    /* Success modal toast */
    .toast-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.7);
      backdrop-filter: blur(4px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 100;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.3s;
    }
    .toast-overlay.active {
      opacity: 1;
      pointer-events: auto;
    }
    .toast-card {
      background: var(--surface);
      border: 1px solid var(--success);
      padding: 24px;
      border-radius: 20px;
      text-align: center;
      max-width: 320px;
      width: 90%;
      box-shadow: 0 20px 40px rgba(0,0,0,0.6);
    }
    .toast-icon {
      width: 60px;
      height: 60px;
      background: rgba(16, 185, 129, 0.15);
      color: var(--success);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 28px;
      margin: 0 auto 12px;
    }
  </style>
</head>
<body>

  <!-- Top Header -->
  <header>
    <div class="brand">
      <img src="image/logo isgi.jpg" alt="ISGI" onerror="this.src='./logo.jpg'" />
      <div>
        <div class="brand-title">ISGI Photo Studio</div>
        <div class="brand-sub">Capture Badge Étudiant</div>
      </div>
    </div>
    <div style="font-size:12px;color:var(--text-muted);font-weight:600">
      📱 Mobile
    </div>
  </header>

  <main>
    <!-- Student Header Card -->
    <div class="student-card" id="studentCard">
      <div class="student-avatar-badge" id="studentInitials">
        <?php echo strtoupper(substr($nom_param ?: 'E', 0, 1)); ?>
      </div>
      <div class="student-meta">
        <div class="student-name" id="studentName">
          <?php echo htmlspecialchars($nom_param ? ($nom_param . ' ' . $prenom_param) : 'Sélectionner un étudiant'); ?>
        </div>
        <div class="student-mat" id="studentMat">
          <?php echo htmlspecialchars($matricule_param ?: 'Rechercher ci-dessous'); ?>
        </div>
        <div class="student-class" id="studentClass">
          <?php echo htmlspecialchars($classe_param ?: 'ISGI Système'); ?>
        </div>
      </div>
    </div>

    <!-- Search if matricule is empty -->
    <div class="search-box" id="searchBox" style="<?php echo $matricule_param ? 'display:none' : ''; ?>">
      <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" id="searchInput" class="search-input" placeholder="Rechercher par nom, prénom ou matricule..." oninput="onSearchInput(this.value)" />
      <div class="student-search-results" id="searchResults"></div>
    </div>

    <!-- Camera / Image Preview Viewport -->
    <div class="camera-container" id="cameraContainer">
      <div class="face-guide" id="faceGuide">
        <span>Centrez le visage</span>
      </div>

      <div class="placeholder-state" id="placeholderState">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
        <p style="font-size:15px;font-weight:700;color:white;margin-bottom:4px">Prendre la photo</p>
        <p style="font-size:12px">Utilisez l'appareil photo du téléphone ou la galerie</p>
      </div>

      <canvas id="photoCanvas" class="preview-canvas" style="display:none"></canvas>
    </div>

    <!-- Hidden native file inputs -->
    <input type="file" id="cameraInput" accept="image/*" capture="environment" style="display:none" onchange="handleImageSelected(this)" />
    <input type="file" id="galleryInput" accept="image/*" style="display:none" onchange="handleImageSelected(this)" />

    <!-- Action Buttons -->
    <div class="action-row">
      <button class="btn btn-camera" onclick="document.getElementById('cameraInput').click()">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
        Appareil Photo
      </button>
      <button class="btn btn-gallery" onclick="document.getElementById('galleryInput').click()">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        Galerie
      </button>
    </div>

    <!-- Adjustment Controls (visible once photo is taken) -->
    <div class="adjust-panel" id="adjustPanel" style="display:none">
      <div class="adjust-title">
        <span>Ajustements Photo</span>
        <button class="rotate-btn" style="width:auto;padding:4px 10px" onclick="rotateImage()">
          🔄 Pivoter 90°
        </button>
      </div>
      <div class="adjust-grid">
        <div class="adjust-item">
          <label>Zoom : <span id="zoomVal">1.0x</span></label>
          <input type="range" id="zoomRange" min="1" max="2.5" step="0.05" value="1" oninput="renderAdjustedCanvas()" />
        </div>
        <div class="adjust-item">
          <label>Luminosité : <span id="brightVal">0%</span></label>
          <input type="range" id="brightRange" min="-50" max="50" step="5" value="0" oninput="renderAdjustedCanvas()" />
        </div>
      </div>
    </div>

    <!-- Save & Send Button -->
    <button class="btn btn-save" id="btnSave" disabled onclick="saveAndSendPhoto()">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Valider et Envoyer au Badge
    </button>
  </main>

  <!-- Success Toast Modal -->
  <div class="toast-overlay" id="toastOverlay">
    <div class="toast-card">
      <div class="toast-icon">✓</div>
      <h3 style="font-size:18px;font-weight:700;margin-bottom:6px">Photo Validée !</h3>
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px">
        La photo a été envoyée avec succès et s'affiche immédiatement sur le badge de l'étudiant.
      </p>
      <button class="btn btn-camera" style="width:100%" onclick="closeToast()">
        Continuer
      </button>
    </div>
  </div>

  <script>
    let currentMatricule = "<?php echo addslashes($matricule_param); ?>";
    let currentSessionId = "<?php echo addslashes($session_id); ?>";
    let originalImage = null;
    let rotationAngle = 0; // 0, 90, 180, 270

    // Sample/local students list for fallback
    let allStudents = [];
    try {
      const saved = localStorage.getItem('isgi_etudiants');
      if (saved) allStudents = JSON.parse(saved);
    } catch(e) {}

    // Handle student search
    function onSearchInput(val) {
      const q = val.trim().toLowerCase();
      const resultsDiv = document.getElementById('searchResults');
      if (!q) {
        resultsDiv.style.display = 'none';
        return;
      }

      const matches = allStudents.filter(s =>
        (s.nom && s.nom.toLowerCase().includes(q)) ||
        (s.prenom && s.prenom.toLowerCase().includes(q)) ||
        (s.matricule && s.matricule.toLowerCase().includes(q))
      ).slice(0, 6);

      if (!matches.length) {
        resultsDiv.innerHTML = '<div style="padding:12px;font-size:12px;color:#94a3b8;text-align:center">Aucun étudiant trouvé</div>';
        resultsDiv.style.display = 'block';
        return;
      }

      resultsDiv.innerHTML = matches.map(s => `
        <div class="result-item" onclick="selectStudent('${s.matricule}', '${s.nom}', '${s.prenom}', '${s.filiere || s.classe_nom || ''}')">
          <div>
            <div style="font-weight:700;font-size:13px">${s.nom} ${s.prenom}</div>
            <div style="font-size:11px;color:#94a3b8">${s.filiere || 'ISGI'}</div>
          </div>
          <span style="font-size:12px;font-weight:700;color:var(--secondary)">${s.matricule}</span>
        </div>
      `).join('');
      resultsDiv.style.display = 'block';
    }

    function selectStudent(mat, nom, prenom, classe) {
      currentMatricule = mat;
      document.getElementById('studentName').textContent = nom + ' ' + prenom;
      document.getElementById('studentMat').textContent = mat;
      document.getElementById('studentClass').textContent = classe || 'ISGI Système';
      document.getElementById('studentInitials').textContent = (nom[0] || 'E').toUpperCase();
      document.getElementById('searchResults').style.display = 'none';
      document.getElementById('searchInput').value = '';
    }

    // Handle Image file selection
    function handleImageSelected(input) {
      if (!input.files || !input.files[0]) return;
      const file = input.files[0];
      const reader = new FileReader();

      reader.onload = function(e) {
        const img = new Image();
        img.onload = function() {
          originalImage = img;
          rotationAngle = 0;
          document.getElementById('placeholderState').style.display = 'none';
          document.getElementById('photoCanvas').style.display = 'block';
          document.getElementById('adjustPanel').style.display = 'block';
          document.getElementById('btnSave').disabled = false;
          renderAdjustedCanvas();
        };
        img.src = e.target.result;
      };
      reader.readAsDataURL(file);
    }

    function rotateImage() {
      rotationAngle = (rotationAngle + 90) % 360;
      renderAdjustedCanvas();
    }

    // Render Canvas with Zoom, Rotation & Brightness
    function renderAdjustedCanvas() {
      if (!originalImage) return;

      const canvas = document.getElementById('photoCanvas');
      const ctx = canvas.getContext('2d');

      const zoom = parseFloat(document.getElementById('zoomRange').value);
      const brightness = parseInt(document.getElementById('brightRange').value);

      document.getElementById('zoomVal').textContent = zoom.toFixed(1) + 'x';
      document.getElementById('brightVal').textContent = (brightness > 0 ? '+' : '') + brightness + '%';

      // Output resolution: 600 x 800 (ratio 3:4 portrait)
      canvas.width = 600;
      canvas.height = 800;

      ctx.save();
      ctx.clearRect(0, 0, canvas.width, canvas.height);

      // Brightness filter
      ctx.filter = `brightness(${100 + brightness}%)`;

      // Translate to center for rotation & zoom
      ctx.translate(canvas.width / 2, canvas.height / 2);
      ctx.rotate((rotationAngle * Math.PI) / 180);
      ctx.scale(zoom, zoom);

      // Draw original image centered
      let drawW = originalImage.width;
      let drawH = originalImage.height;

      // Fit aspect ratio
      const scale = Math.max(canvas.width / drawW, canvas.height / drawH);
      drawW *= scale;
      drawH *= scale;

      ctx.drawImage(originalImage, -drawW / 2, -drawH / 2, drawW, drawH);
      ctx.restore();
    }

    // Save and send photo to server & PC app
    async function saveAndSendPhoto() {
      if (!currentMatricule) {
        alert("Veuillez d'abord sélectionner un étudiant");
        return;
      }

      const canvas = document.getElementById('photoCanvas');
      const btn = document.getElementById('btnSave');
      btn.disabled = true;
      btn.innerHTML = 'Envoi en cours...';

      // Convert canvas to JPEG Base64
      const base64Data = canvas.toDataURL('image/jpeg', 0.92);

      try {
        const payload = {
          matricule: currentMatricule,
          session_id: currentSessionId,
          image: base64Data
        };

        const res = await fetch('ajax/save_student_photo.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });

        const data = await res.json();
        if (data.success) {
          // Show success toast
          document.getElementById('toastOverlay').classList.add('active');
        } else {
          alert("Erreur : " + (data.error || "Impossible d'enregistrer la photo"));
        }
      } catch(err) {
        console.error(err);
        alert("Erreur de connexion avec le serveur");
      } finally {
        btn.disabled = false;
        btn.innerHTML = '✓ Valider et Envoyer au Badge';
      }
    }

    function closeToast() {
      document.getElementById('toastOverlay').classList.remove('active');
      // If we had a specific session, inform the user they can take another
      if (!currentSessionId) {
        document.getElementById('searchBox').style.display = 'block';
        document.getElementById('studentName').textContent = 'Sélectionner un étudiant';
        document.getElementById('studentMat').textContent = 'Rechercher ci-dessous';
        document.getElementById('studentInitials').textContent = '?';
        currentMatricule = '';
      }
    }
  </script>
</body>
</html>
