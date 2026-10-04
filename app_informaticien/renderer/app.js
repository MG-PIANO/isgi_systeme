// ═══════════════════════════════════════════════════════
//  ISGI Informaticien — Application Logic
//  Badge Generation | Vidéos | Étudiants | Supabase Sync
// ═══════════════════════════════════════════════════════

// ── State ──────────────────────────────────────────────
let currentPhoto = null; // { base64: '...', ext: 'jpg' }
let badgeDB = JSON.parse(localStorage.getItem('isgi_badges') || '[]');
let videoDB = JSON.parse(localStorage.getItem('isgi_videos') || '[]');
let etudiantsDB = JSON.parse(localStorage.getItem('isgi_etudiants') || '[]');
let currentVideoPath = null;
let currentThumbBase64 = null;
let batchStudents = [];
let appConfig = JSON.parse(localStorage.getItem('isgi_config') || '{}');

// Studio State
let activeStudentId = null;
let studioFilter = 'all'; // 'all', 'no-photo', 'has-photo'
let activeBadgeSide = 'recto'; // 'recto' or 'verso'
let phonePollInterval = null;
let currentPhoneSessionId = null;
let webcamStream = null;

// Preload Official Templates
const templateRectoImg = new Image();
templateRectoImg.src = './images/badge_recto.png';
const templateVersoImg = new Image();
templateVersoImg.src = './images/badge_verso.png';

// ── Init ───────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  // App version
  if (window.electronAPI) {
    const v = await window.electronAPI.getVersion();
    document.getElementById('appVersion').textContent = `v${v}`;
    document.getElementById('aboutVersion').textContent = v;
  }

  // Load config
  if (appConfig.supabaseUrl) document.getElementById('supabase-url').value = appConfig.supabaseUrl;
  if (appConfig.supabaseKey) document.getElementById('supabase-key').value = appConfig.supabaseKey;
  if (appConfig.directeur) document.getElementById('param-directeur').value = appConfig.directeur;
  if (appConfig.outputDir) document.getElementById('param-output-dir').value = appConfig.outputDir;

  // Initialize official badge studio
  initBadgeStudio();

  // Load dashboard stats
  updateDashboardStats();
  renderRecentBadges();
  renderVideoGrid();

  // Check connection
  checkSupabaseConnection();
});

// ── Navigation ─────────────────────────────────────────
function navigate(page) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));

  const pageEl = document.getElementById(`page-${page}`);
  if (pageEl) pageEl.classList.add('active');

  const navEl = document.querySelector(`[data-page="${page}"]`);
  if (navEl) navEl.classList.add('active');

  // Page-specific inits
  if (page === 'badge-create') initBadgeStudio();
  if (page === 'badge-list') renderBadgeTable();
  if (page === 'videos') renderVideoGrid();
  if (page === 'etudiants') renderEtudiantsTable();
}

// ── Dashboard Stats ────────────────────────────────────
function updateDashboardStats() {
  document.getElementById('statBadgesTotal').textContent = badgeDB.length;
  document.getElementById('statEtudiants').textContent = etudiantsDB.length;
  document.getElementById('statVideos').textContent = videoDB.length;

  const today = new Date().toISOString().split('T')[0];
  const todayBadges = badgeDB.filter(b => b.dateGeneration?.startsWith(today)).length;
  document.getElementById('statBadgesAujourdhui').textContent = todayBadges;
}

// ── Recent Badges ──────────────────────────────────────
function renderRecentBadges() {
  const container = document.getElementById('recentBadges');
  const recent = [...badgeDB].reverse().slice(0, 5);

  if (!recent.length) {
    container.innerHTML = '<div class="empty-state-sm">Aucun badge récent</div>';
    return;
  }

  container.innerHTML = recent.map(b => `
    <div class="recent-badge-item">
      <div class="rb-avatar">${(b.nom||'?')[0]}${(b.prenom||'?')[0]}</div>
      <div class="rb-info">
        <div class="rb-name">${b.nom} ${b.prenom}</div>
        <div class="rb-meta">${b.filiere || '—'} · ${b.niveau || '—'}</div>
      </div>
      <span class="rb-action" onclick="regenerateBadge('${b.id}')">↓ PDF</span>
    </div>
  `).join('');
}

// ══ OFFICIAL BADGE STUDIO ═════════════════════════════

async function initBadgeStudio() {
  renderBadgeStudioStudents();

  // If no student is selected, select the first one if available
  if (!activeStudentId && etudiantsDB.length > 0) {
    selectStudentForBadge(etudiantsDB[0].id || etudiantsDB[0].matricule);
  } else {
    renderOfficialBadgeCanvas();
  }
}

// ── Students List & Search in Studio ───────────────────
function filterBadgeStudentList() {
  renderBadgeStudioStudents();
}

function setStudentListFilter(filter, btn) {
  studioFilter = filter;
  document.querySelectorAll('.badge-pill-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  renderBadgeStudioStudents();
}

function renderBadgeStudioStudents() {
  const listEl = document.getElementById('badgeStudentList');
  const countEl = document.getElementById('badgeStudentCount');
  if (!listEl) return;

  const search = (document.getElementById('badgeStudentSearch')?.value || '').toLowerCase().trim();

  // Generate initial mock students if database is totally empty
  if (!etudiantsDB.length) {
    etudiantsDB = generateDemoStudents('', '', 8);
    localStorage.setItem('isgi_etudiants', JSON.stringify(etudiantsDB));
  }

  const filtered = etudiantsDB.filter(s => {
    const textMatch = !search ||
      `${s.nom} ${s.prenom} ${s.matricule} ${s.filiere}`.toLowerCase().includes(search);
    if (!textMatch) return false;

    if (studioFilter === 'has-photo') return !!s.photo;
    if (studioFilter === 'no-photo') return !s.photo;
    return true;
  });

  if (countEl) countEl.textContent = filtered.length;

  if (!filtered.length) {
    listEl.innerHTML = '<div class="empty-state-sm">Aucun étudiant trouvé</div>';
    return;
  }

  listEl.innerHTML = filtered.map(s => {
    const isAct = s.id === activeStudentId || s.matricule === document.getElementById('badge-matricule')?.value;
    const hasPhoto = !!s.photo;
    const photoSrc = s.photo ? (s.photo.startsWith('data:') || s.photo.startsWith('http') ? s.photo : `../${s.photo}`) : null;

    return `
      <div class="badge-student-item ${isAct ? 'active' : ''}" onclick="selectStudentForBadge('${s.id || s.matricule}')">
        <div class="bs-avatar">
          ${photoSrc ? `<img src="${photoSrc}" alt="${s.nom}" />` : `<span>${(s.nom||'?')[0]}${(s.prenom||'?')[0]}</span>`}
        </div>
        <div class="bs-info">
          <div class="bs-name">${s.nom} ${s.prenom}</div>
          <div class="bs-meta">
            <span>${s.matricule}</span>
            <span class="bs-badge-pill ${hasPhoto ? 'has-photo' : 'no-photo'}">
              ${hasPhoto ? '📷 Photo OK' : '⚠ Sans photo'}
            </span>
          </div>
        </div>
      </div>
    `;
  }).join('');
}

function selectStudentForBadge(idOrMat) {
  const student = etudiantsDB.find(s => s.id === idOrMat || s.matricule === idOrMat);
  if (!student) return;

  activeStudentId = student.id || student.matricule;

  if (document.getElementById('badge-nom')) document.getElementById('badge-nom').value = student.nom || '';
  if (document.getElementById('badge-prenom')) document.getElementById('badge-prenom').value = student.prenom || '';
  if (document.getElementById('badge-matricule')) document.getElementById('badge-matricule').value = student.matricule || '';
  if (document.getElementById('badge-lieu')) document.getElementById('badge-lieu').value = student.lieu || 'Brazzaville';
  if (document.getElementById('badge-filiere') && student.filiere) document.getElementById('badge-filiere').value = student.filiere;
  if (document.getElementById('badge-niveau') && student.niveau) document.getElementById('badge-niveau').value = student.niveau;

  if (student.photo) {
    setStudentPhotoData(student.photo, false);
  } else {
    clearStudentPhoto(false);
  }

  renderBadgeStudioStudents();
  renderOfficialBadgeCanvas();
}

// ── Photo Handling ─────────────────────────────────────
async function selectStudentPhoto() {
  if (!window.electronAPI) {
    // Browser fallback
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.onchange = (e) => {
      const file = e.target.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = (evt) => setStudentPhotoData(evt.target.result);
      reader.readAsDataURL(file);
    };
    input.click();
    return;
  }

  const result = await window.electronAPI.selectPhoto();
  if (!result) return;
  setStudentPhotoData(`data:image/${result.ext};base64,${result.base64}`);
}

function setStudentPhotoData(base64Data, notify = true) {
  currentPhoto = {
    base64: base64Data,
    ext: 'jpeg'
  };

  const thumb = document.getElementById('photoThumbImg');
  const emptyIcon = document.getElementById('photoEmptyIcon');

  if (thumb && emptyIcon) {
    thumb.src = base64Data.startsWith('data:') ? base64Data : `data:image/jpeg;base64,${base64Data}`;
    thumb.classList.remove('hidden');
    emptyIcon.classList.add('hidden');
  }

  // Update in local DB for this student
  const mat = document.getElementById('badge-matricule')?.value;
  if (mat) {
    const student = etudiantsDB.find(s => s.matricule === mat || s.id === activeStudentId);
    if (student) {
      student.photo = base64Data;
      student.has_photo = true;
      localStorage.setItem('isgi_etudiants', JSON.stringify(etudiantsDB));
      renderBadgeStudioStudents();
    }
  }

  renderOfficialBadgeCanvas();
  if (notify) toast('Photo mise à jour sur le badge !', 'success');
}

function clearStudentPhoto(notify = true) {
  currentPhoto = null;
  const thumb = document.getElementById('photoThumbImg');
  const emptyIcon = document.getElementById('photoEmptyIcon');

  if (thumb) {
    thumb.src = '';
    thumb.classList.add('hidden');
  }
  if (emptyIcon) emptyIcon.classList.remove('hidden');

  const mat = document.getElementById('badge-matricule')?.value;
  if (mat) {
    const student = etudiantsDB.find(s => s.matricule === mat || s.id === activeStudentId);
    if (student) {
      delete student.photo;
      student.has_photo = false;
      localStorage.setItem('isgi_etudiants', JSON.stringify(etudiantsDB));
      renderBadgeStudioStudents();
    }
  }

  renderOfficialBadgeCanvas();
  if (notify) toast('Photo retirée du badge', 'info');
}

// ── Smartphone Studio QR Modal & Polling ───────────────
function startPhonePhotoSession() {
  const nom = (document.getElementById('badge-nom')?.value || '').trim();
  const prenom = (document.getElementById('badge-prenom')?.value || '').trim();
  const matricule = (document.getElementById('badge-matricule')?.value || '').trim();

  if (!matricule) {
    toast('Veuillez d\'abord sélectionner un étudiant ou saisir son matricule', 'info');
    return;
  }

  currentPhoneSessionId = 'sess_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6);
  const localIp = '172.16.202.33';
  const url = `http://${localIp}/isgi_system/studio_photo_mobile.php?session=${currentPhoneSessionId}&matricule=${encodeURIComponent(matricule)}&nom=${encodeURIComponent(nom)}&prenom=${encodeURIComponent(prenom)}`;

  // Generate QR code for phone scan
  const qrCanvas = document.getElementById('phoneQRCanvas');
  if (qrCanvas && typeof QRCode !== 'undefined') {
    QRCode.toCanvas(qrCanvas, url, {
      width: 220,
      margin: 1,
      color: { dark: '#003087', light: '#ffffff' }
    });
  }

  const linkEl = document.getElementById('phoneModalUrlLink');
  if (linkEl) {
    linkEl.href = url;
    linkEl.textContent = url;
  }

  const nameEl = document.getElementById('phoneModalStudentName');
  if (nameEl) {
    nameEl.textContent = `${nom} ${prenom} (${matricule})`;
  }

  const statusText = document.getElementById('phoneModalStatusText');
  if (statusText) statusText.textContent = 'En attente de capture du smartphone...';

  document.getElementById('phonePhotoModal')?.classList.remove('hidden');

  // Start polling
  if (phonePollInterval) clearInterval(phonePollInterval);
  phonePollInterval = setInterval(async () => {
    try {
      const res = await fetch(`http://${localIp}/isgi_system/ajax/check_photo_session.php?session_id=${currentPhoneSessionId}&t=${Date.now()}`);
      if (!res.ok) return;
      const data = await res.json();
      if (data.ready && data.base64) {
        clearInterval(phonePollInterval);
        phonePollInterval = null;

        setStudentPhotoData(data.base64);

        if (statusText) {
          statusText.innerHTML = '<span style="color:#10b981;font-weight:700">✓ Photo reçue avec succès !</span>';
        }
        toast('Photo reçue du smartphone et appliquée au badge !', 'success');

        setTimeout(() => {
          closePhonePhotoModal();
        }, 1200);
      }
    } catch (e) {
      // Ignore polling errors
    }
  }, 1200);
}

function closePhonePhotoModal() {
  if (phonePollInterval) {
    clearInterval(phonePollInterval);
    phonePollInterval = null;
  }
  document.getElementById('phonePhotoModal')?.classList.add('hidden');
}

// ── Webcam PC Capture ──────────────────────────────────
async function openWebcamModal() {
  try {
    const video = document.getElementById('webcamVideo');
    webcamStream = await navigator.mediaDevices.getUserMedia({
      video: { width: { ideal: 1280 }, height: { ideal: 960 }, facingMode: 'user' },
      audio: false
    });
    video.srcObject = webcamStream;
    document.getElementById('webcamModal')?.classList.remove('hidden');
  } catch (err) {
    toast('Impossible d\'accéder à la caméra : ' + err.message, 'error');
  }
}

function closeWebcamModal() {
  if (webcamStream) {
    webcamStream.getTracks().forEach(t => t.stop());
    webcamStream = null;
  }
  document.getElementById('webcamModal')?.classList.add('hidden');
}

function captureWebcamPhoto() {
  const video = document.getElementById('webcamVideo');
  const canvas = document.getElementById('webcamCanvas');
  if (!video || !canvas) return;

  const vW = video.videoWidth || 640;
  const vH = video.videoHeight || 480;
  const targetRatio = 3 / 4;
  let sW, sH, sX, sY;

  if (vW / vH > targetRatio) {
    sH = vH;
    sW = vH * targetRatio;
    sX = (vW - sW) / 2;
    sY = 0;
  } else {
    sW = vW;
    sH = vW / targetRatio;
    sX = 0;
    sY = (vH - sH) / 2;
  }

  canvas.width = 600;
  canvas.height = 800;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(video, sX, sY, sW, sH, 0, 0, 600, 800);

  const base64Data = canvas.toDataURL('image/jpeg', 0.92);
  closeWebcamModal();
  setStudentPhotoData(base64Data);
  toast('Photo capturée par webcam !', 'success');
}

// ── Switch Recto / Verso ───────────────────────────────
function switchBadgeSide(side) {
  activeBadgeSide = side;
  const canvas = document.getElementById('badgeOfficialCanvas');
  const versoImg = document.getElementById('badgeOfficialVersoImg');
  const tabRecto = document.getElementById('tabBadgeRecto');
  const tabVerso = document.getElementById('tabBadgeVerso');

  if (side === 'recto') {
    if (canvas) canvas.style.display = 'block';
    if (versoImg) versoImg.style.display = 'none';
    if (tabRecto) tabRecto.classList.add('active');
    if (tabVerso) tabVerso.classList.remove('active');
  } else {
    if (canvas) canvas.style.display = 'none';
    if (versoImg) versoImg.style.display = 'block';
    if (tabRecto) tabRecto.classList.remove('active');
    if (tabVerso) tabVerso.classList.add('active');
  }
}

// ── Official Canvas 1063 x 1535 Rendering ──────────────
function updateBadgeCanvas() {
  renderOfficialBadgeCanvas();
}

async function renderOfficialBadgeCanvas() {
  const canvas = document.getElementById('badgeOfficialCanvas');
  if (!canvas) return;

  const student = {
    nom: (document.getElementById('badge-nom')?.value || '').trim().toUpperCase(),
    prenom: (document.getElementById('badge-prenom')?.value || '').trim(),
    matricule: (document.getElementById('badge-matricule')?.value || '').trim().toUpperCase(),
    lieu: (document.getElementById('badge-lieu')?.value || '').trim(),
    filiere: (document.getElementById('badge-filiere')?.value || '').trim(),
    niveau: (document.getElementById('badge-niveau')?.value || '').trim(),
    annee: (document.getElementById('badge-annee')?.value || '').trim(),
  };

  await renderBadgeToCanvas(canvas, student, currentPhoto);
}

async function renderBadgeToCanvas(canvas, student, photoObj) {
  const ctx = canvas.getContext('2d');

  // Ensure template image is ready
  if (!templateRectoImg.complete || templateRectoImg.naturalWidth === 0) {
    await new Promise(resolve => {
      templateRectoImg.onload = resolve;
      templateRectoImg.onerror = resolve;
    });
  }

  // 1. Draw Official Template Recto
  ctx.clearRect(0, 0, 1063, 1535);
  ctx.drawImage(templateRectoImg, 0, 0, 1063, 1535);

  // 2. Draw Rounded Student Photo (x: 322, y: 400, w: 426, h: 452, radius: 28)
  if (photoObj && photoObj.base64) {
    await drawRoundedPhoto(ctx, photoObj, 322, 400, 426, 452, 28);
  }

  // 3. Draw Student Texts (Aligned at x = 360, color white bold)
  ctx.fillStyle = '#FFFFFF';
  ctx.textBaseline = 'middle';

  function drawFitText(text, x, y, maxW, fontStyle) {
    ctx.font = fontStyle;
    if (!text) return;
    let t = text;
    while (ctx.measureText(t).width > maxW && t.length > 3) {
      t = t.substring(0, t.length - 2) + '…';
    }
    ctx.fillText(t, x, y);
  }

  // NOM(S) : at y = 1072
  drawFitText(student.nom || '—', 360, 1072, 600, 'bold 26px "Segoe UI", Arial, sans-serif');

  // PRÉNOM(S) : at y = 1130
  drawFitText(student.prenom || '—', 360, 1130, 600, 'bold 26px "Segoe UI", Arial, sans-serif');

  // NÉ(E) LE LIEU : at y = 1188
  drawFitText(student.lieu || '—', 360, 1188, 600, 'bold 24px "Segoe UI", Arial, sans-serif');

  // FILIÈRE : at y = 1246
  drawFitText(student.filiere || '—', 360, 1246, 600, 'bold 24px "Segoe UI", Arial, sans-serif');

  // NIVEAU : at y = 1304
  drawFitText(student.niveau || '—', 360, 1304, 380, 'bold 24px "Segoe UI", Arial, sans-serif');

  // N°ISGI : [matricule] at y = 1410 (Orange Banner)
  if (student.matricule) {
    drawFitText(`N°ISGI : ${student.matricule}`, 360, 1410, 410, 'bold 30px "Segoe UI", Arial, sans-serif');
  }

  // 4. Draw QR Code in white square (x: 770, y: 1295, w: 195, h: 195)
  const qrContent = student.matricule || 'ISGI-BADGE';
  await drawQRCodeOnCanvas(ctx, qrContent, 772, 1297, 191, 191);
}

function drawRoundedPhoto(ctx, photoObj, x, y, w, h, radius) {
  return new Promise((resolve) => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
      ctx.save();
      ctx.beginPath();
      if (ctx.roundRect) {
        ctx.roundRect(x, y, w, h, radius);
      } else {
        // Fallback rounded rect
        ctx.moveTo(x + radius, y);
        ctx.arcTo(x + w, y, x + w, y + h, radius);
        ctx.arcTo(x + w, y + h, x, y + h, radius);
        ctx.arcTo(x, y + h, x, y, radius);
        ctx.arcTo(x, y, x + w, y, radius);
        ctx.closePath();
      }
      ctx.clip();

      // Cover aspect scaling
      const imgRatio = img.width / img.height;
      const targetRatio = w / h;
      let drawW, drawH, drawX, drawY;

      if (imgRatio > targetRatio) {
        drawH = h;
        drawW = h * imgRatio;
        drawX = x - (drawW - w) / 2;
        drawY = y;
      } else {
        drawW = w;
        drawH = w / imgRatio;
        drawX = x;
        drawY = y - (drawH - h) / 2;
      }

      ctx.drawImage(img, drawX, drawY, drawW, drawH);
      ctx.restore();
      resolve();
    };
    img.onerror = resolve;
    const src = photoObj.base64.startsWith('data:')
      ? photoObj.base64
      : `data:image/${photoObj.ext || 'jpeg'};base64,${photoObj.base64}`;
    img.src = src;
  });
}

function drawQRCodeOnCanvas(ctx, text, x, y, w, h) {
  return new Promise((resolve) => {
    if (typeof QRCode === 'undefined') return resolve();
    const tempCanvas = document.createElement('canvas');
    QRCode.toCanvas(tempCanvas, text, {
      width: w,
      margin: 1,
      color: { dark: '#003087', light: '#ffffff' }
    }, (err) => {
      if (!err) {
        ctx.drawImage(tempCanvas, x, y, w, h);
      }
      resolve();
    });
  });
}

// ── Export PNG HD (300 DPI) ────────────────────────────
async function exportBadgePNG() {
  const nom = (document.getElementById('badge-nom')?.value || 'ETUDIANT').trim();
  const prenom = (document.getElementById('badge-prenom')?.value || '').trim();
  const canvas = document.getElementById('badgeOfficialCanvas');
  if (!canvas) return;

  const imageBase64 = canvas.toDataURL('image/png', 1.0);

  if (window.electronAPI && window.electronAPI.saveImage) {
    const res = await window.electronAPI.saveImage({
      imageBase64,
      studentName: `${nom}_${prenom}`
    });
    if (res && res.success) {
      toast(`Badge PNG sauvegardé : ${res.filePath}`, 'success');
      return;
    }
  }

  // Fallback download in browser
  const link = document.createElement('a');
  link.href = imageBase64;
  link.download = `Badge_ISGI_${nom}_${prenom}.png`;
  link.click();
  toast('Badge PNG téléchargé !', 'success');
}

// ── Direct Print ───────────────────────────────────────
function printBadge() {
  const canvas = document.getElementById('badgeOfficialCanvas');
  if (!canvas) return;
  const rectoData = canvas.toDataURL('image/png');
  const versoImg = document.getElementById('badgeOfficialVersoImg');
  const versoData = versoImg ? versoImg.src : './images/badge_verso.png';

  const printWindow = window.open('', '_blank');
  printWindow.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
      <title>Impression Badge ISGI</title>
      <style>
        @page { size: auto; margin: 10mm; }
        body { margin: 0; display: flex; gap: 15mm; justify-content: center; align-items: center; min-height: 100vh; font-family: sans-serif; background: #fff; }
        .badge-card { width: 54mm; height: 85.6mm; border-radius: 3mm; overflow: hidden; box-shadow: 0 0 1px #000; }
        .badge-card img { width: 100%; height: 100%; object-fit: cover; display: block; }
        @media print {
          body { min-height: unset; }
          .badge-card { box-shadow: none; border: 0.5px solid #ccc; }
        }
      </style>
    </head>
    <body>
      <div class="badge-card"><img src="${rectoData}" /></div>
      <div class="badge-card"><img src="${versoData}" /></div>
      <script>
        window.onload = () => { window.print(); window.close(); };
      </script>
    </body>
    </html>
  `);
  printWindow.document.close();
}

// ── Generate Badge PDF (Official Recto + Verso) ────────
async function generateBadgePDF() {
  const nom = (document.getElementById('badge-nom')?.value || '').trim();
  const prenom = (document.getElementById('badge-prenom')?.value || '').trim();
  const matricule = (document.getElementById('badge-matricule')?.value || '').trim();
  const lieu = (document.getElementById('badge-lieu')?.value || '').trim();
  const filiere = document.getElementById('badge-filiere')?.value || '';
  const niveau = document.getElementById('badge-niveau')?.value || '';
  const annee = document.getElementById('badge-annee')?.value || '';

  if (!nom || !matricule) {
    toast('Veuillez renseigner au moins le nom et le matricule', 'error');
    return;
  }

  showLoading('Génération du badge officiel PDF...');

  try {
    const canvas = document.getElementById('badgeOfficialCanvas');
    const rectoPngBase64 = canvas.toDataURL('image/png').replace(/^data:image\/png;base64,/, '');

    // Fetch Verso image bytes
    const versoResponse = await fetch('./images/badge_verso.png');
    const versoBuffer = await versoResponse.arrayBuffer();

    const { PDFDocument, rgb } = PDFLib;
    const pdfDoc = await PDFDocument.create();

    // A4 Landscape: 841.89 x 595.28 pt
    const page = pdfDoc.addPage([841.89, 595.28]);
    const { width, height } = page.getSize();

    const rectoImage = await pdfDoc.embedPng(rectoPngBase64);
    const versoImage = await pdfDoc.embedPng(versoBuffer);

    // CR-80 format (proportions 1063 x 1535 = 1 : 1.444)
    const cardW = 240;
    const cardH = 240 * (1535 / 1063); // ~ 346.5 pt
    const cardY = (height - cardH) / 2;
    const gap = 50;
    const totalW = cardW * 2 + gap;
    const startX = (width - totalW) / 2;

    // Draw Recto
    page.drawImage(rectoImage, {
      x: startX,
      y: cardY,
      width: cardW,
      height: cardH,
    });

    // Draw Verso
    page.drawImage(versoImage, {
      x: startX + cardW + gap,
      y: cardY,
      width: cardW,
      height: cardH,
    });

    const font = await pdfDoc.embedFont(PDFLib.StandardFonts.HelveticaBold);
    const fontReg = await pdfDoc.embedFont(PDFLib.StandardFonts.Helvetica);

    page.drawText('RECTO', { x: startX + cardW / 2 - 20, y: cardY + cardH + 15, size: 11, font, color: rgb(0, 48/255, 135/255) });
    page.drawText('VERSO', { x: startX + cardW + gap + cardW / 2 - 20, y: cardY + cardH + 15, size: 11, font, color: rgb(0, 48/255, 135/255) });

    page.drawText(`ISGI · Carte d'Étudiant · ${nom} ${prenom} · ${matricule} · ${filiere} · ${niveau}`, {
      x: startX,
      y: 35,
      size: 9,
      font: fontReg,
      color: rgb(0.4, 0.4, 0.4)
    });

    const pdfBytes = await pdfDoc.save();
    const pdfBase64 = Buffer.from(pdfBytes).toString('base64');

    if (window.electronAPI && window.electronAPI.savePDF) {
      const result = await window.electronAPI.savePDF({
        pdfBase64,
        studentName: `${nom}_${prenom}`
      });
      if (result && result.success) {
        saveBadgeToDB({ nom, prenom, matricule, lieu, filiere, niveau, annee });
        toast(`Badge PDF sauvegardé : ${result.filePath}`, 'success');
      }
    } else {
      const blob = new Blob([pdfBytes], { type: 'application/pdf' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `Badge_ISGI_${nom}_${prenom}.pdf`;
      a.click();
      saveBadgeToDB({ nom, prenom, matricule, lieu, filiere, niveau, annee });
      toast('Badge PDF téléchargé !', 'success');
    }
  } catch (err) {
    console.error('PDF error:', err);
    toast('Erreur lors de la création du PDF : ' + err.message, 'error');
  } finally {
    hideLoading();
  }
}

// ── Save to Database & LocalStorage ────────────────────
function saveBadgeToDB(student) {
  const badge = {
    id: `badge-${Date.now()}`,
    ...student,
    dateGeneration: new Date().toISOString(),
  };
  badgeDB.unshift(badge);
  localStorage.setItem('isgi_badges', JSON.stringify(badgeDB));
  updateDashboardStats();
  renderRecentBadges();
}

function saveBadge() {
  const nom = (document.getElementById('badge-nom')?.value || '').trim();
  const prenom = (document.getElementById('badge-prenom')?.value || '').trim();
  const matricule = (document.getElementById('badge-matricule')?.value || '').trim();
  const lieu = (document.getElementById('badge-lieu')?.value || '').trim();
  const filiere = document.getElementById('badge-filiere')?.value || '';
  const niveau = document.getElementById('badge-niveau')?.value || '';
  const annee = document.getElementById('badge-annee')?.value || '';

  if (!nom || !matricule) {
    toast('Nom et Matricule sont obligatoires', 'error');
    return;
  }

  saveBadgeToDB({ nom, prenom, matricule, lieu, filiere, niveau, annee });
  toast('Badge enregistré dans la base locale !', 'success');
}

function resetBadgeForm() {
  if (document.getElementById('badge-nom')) document.getElementById('badge-nom').value = '';
  if (document.getElementById('badge-prenom')) document.getElementById('badge-prenom').value = '';
  if (document.getElementById('badge-matricule')) document.getElementById('badge-matricule').value = '';
  if (document.getElementById('badge-lieu')) document.getElementById('badge-lieu').value = '';
  if (document.getElementById('badge-filiere')) document.getElementById('badge-filiere').value = '';
  if (document.getElementById('badge-niveau')) document.getElementById('badge-niveau').value = '';
  activeStudentId = null;
  clearStudentPhoto(false);
  renderBadgeStudioStudents();
  renderOfficialBadgeCanvas();
  toast('Formulaire réinitialisé', 'info');
}

async function regenerateBadge(id) {
  const badge = badgeDB.find(b => b.id === id);
  if (!badge) return;
  navigate('badge-create');
  setTimeout(() => {
    document.getElementById('badge-nom').value = badge.nom;
    document.getElementById('badge-prenom').value = badge.prenom;
    document.getElementById('badge-matricule').value = badge.matricule;
    document.getElementById('badge-lieu').value = badge.lieu || '';
    document.getElementById('badge-filiere').value = badge.filiere || '';
    document.getElementById('badge-niveau').value = badge.niveau || '';
    document.getElementById('badge-annee').value = badge.annee || '2026 - 2027';
    renderOfficialBadgeCanvas();
  }, 100);
}

// ══ BADGE LIST ════════════════════════════════════════

function renderBadgeTable(filter = '') {
  const tbody = document.getElementById('badgeTableBody');
  let list = filter
    ? badgeDB.filter(b => `${b.nom} ${b.prenom} ${b.matricule}`.toLowerCase().includes(filter.toLowerCase()))
    : badgeDB;

  if (!list.length) {
    tbody.innerHTML = '<tr><td colspan="7" class="empty-cell">Aucun badge trouvé</td></tr>';
    return;
  }

  tbody.innerHTML = list.map(b => `
    <tr>
      <td><code style="color:var(--orange);font-size:12px">${b.matricule}</code></td>
      <td><strong>${b.nom}</strong> ${b.prenom}</td>
      <td>${b.filiere || '—'}</td>
      <td>${b.niveau || '—'}</td>
      <td>${b.annee || '—'}</td>
      <td style="color:var(--text-muted);font-size:12px">${new Date(b.dateGeneration).toLocaleDateString('fr-FR')}</td>
      <td>
        <button class="btn btn-sm btn-outline" onclick="regenerateBadge('${b.id}')" title="Régénérer">↓ PDF</button>
        <button class="btn btn-sm" style="background:rgba(239,68,68,0.1);color:#ef4444;margin-left:4px" onclick="deleteBadge('${b.id}')">✕</button>
      </td>
    </tr>
  `).join('');
}

function filterBadgeList() {
  renderBadgeTable(document.getElementById('badgeSearch').value);
}

function deleteBadge(id) {
  if (!confirm('Supprimer ce badge de la base ?')) return;
  badgeDB = badgeDB.filter(b => b.id !== id);
  localStorage.setItem('isgi_badges', JSON.stringify(badgeDB));
  renderBadgeTable();
  updateDashboardStats();
}

// ══ BATCH GENERATION ═════════════════════════════════

function loadBatchStudents() {
  const filiere = document.getElementById('batch-filiere').value;
  const niveau = document.getElementById('batch-niveau').value;

  batchStudents = etudiantsDB.filter(e => {
    const matchF = !filiere || e.filiere === filiere;
    const matchN = !niveau || e.niveau === niveau;
    return matchF && matchN;
  });

  // If no real students, add demo
  if (!batchStudents.length && (filiere || niveau)) {
    batchStudents = generateDemoStudents(filiere, niveau, 5);
  }

  const count = batchStudents.length;
  document.getElementById('batchCount').textContent = `${count} étudiant(s) sélectionné(s)`;
  document.getElementById('batchGenBtn').disabled = count === 0;

  const tbody = document.getElementById('batchStudentList');
  if (!count) {
    tbody.innerHTML = '<div class="empty-state">Aucun étudiant trouvé pour ce filtre</div>';
    return;
  }

  tbody.innerHTML = batchStudents.map((e, i) => `
    <div class="batch-student-item">
      <div class="rb-avatar">${(e.nom||'?')[0]}${(e.prenom||'?')[0]}</div>
      <div style="flex:1">
        <div style="font-size:13px;font-weight:600">${e.nom} ${e.prenom}</div>
        <div style="font-size:11px;color:var(--text-muted)">${e.matricule} · ${e.filiere || filiere} · ${e.niveau || niveau}</div>
      </div>
      <span style="font-size:11px;color:var(--text-muted)">#${i+1}</span>
    </div>
  `).join('');
}

function generateDemoStudents(filiere, niveau, n) {
  const noms = ['NGOMA', 'MBEMBA', 'MOUKALA', 'NZITA', 'BAKALA', 'MOUTOU', 'KIMINOU'];
  const prenoms = ['Jean', 'Marie', 'Pierre', 'Aline', 'David', 'Sandra', 'Kevin'];
  return Array.from({length: n}, (_, i) => ({
    id: `demo-${i}`,
    nom: noms[i % noms.length],
    prenom: prenoms[i % prenoms.length],
    matricule: `ISGI-${new Date().getFullYear()}-${String(i+1).padStart(3,'0')}`,
    filiere: filiere || 'Informatique de Gestion',
    niveau: niveau || 'Licence 1',
    lieu: 'Brazzaville',
  }));
}

async function generateBatchPDF() {
  if (!batchStudents.length) return;

  const annee = document.getElementById('batch-annee').value;
  const filiere = document.getElementById('batch-filiere').value || 'Toutes';
  const progressOverlay = document.getElementById('batchProgress');
  const progressBar = document.getElementById('batchProgressBar');
  const progressText = document.getElementById('batchProgressText');

  progressOverlay.classList.remove('hidden');

  try {
    const { PDFDocument, rgb } = PDFLib;
    const masterPdf = await PDFDocument.create();

    // Fetch Verso image bytes
    const versoResponse = await fetch('./images/badge_verso.png');
    const versoBuffer = await versoResponse.arrayBuffer();
    const versoImage = await masterPdf.embedPng(versoBuffer);

    // Create offscreen canvas for rendering official recto
    const offCanvas = document.createElement('canvas');
    offCanvas.width = 1063;
    offCanvas.height = 1535;

    for (let i = 0; i < batchStudents.length; i++) {
      const student = { ...batchStudents[i], annee, directeur: appConfig.directeur || 'ELESSA FREDERIC' };

      // Update progress
      const pct = Math.round(((i + 1) / batchStudents.length) * 100);
      progressBar.style.width = `${pct}%`;
      progressText.textContent = `${i + 1} / ${batchStudents.length}`;

      // Render official recto canvas for this student
      const photoObj = student.photo ? { base64: student.photo, ext: 'jpeg' } : null;
      await renderBadgeToCanvas(offCanvas, student, photoObj);
      const rectoPngBase64 = offCanvas.toDataURL('image/png').replace(/^data:image\/png;base64,/, '');
      const rectoImage = await masterPdf.embedPng(rectoPngBase64);

      // Add A4 landscape page (841.89 x 595.28 pt)
      const page = masterPdf.addPage([841.89, 595.28]);
      const { width, height } = page.getSize();

      const cardW = 240;
      const cardH = 240 * (1535 / 1063);
      const cardY = (height - cardH) / 2;
      const gap = 50;
      const totalW = cardW * 2 + gap;
      const startX = (width - totalW) / 2;

      // Draw Recto & Verso
      page.drawImage(rectoImage, { x: startX, y: cardY, width: cardW, height: cardH });
      page.drawImage(versoImage, { x: startX + cardW + gap, y: cardY, width: cardW, height: cardH });

      const font = await masterPdf.embedFont(PDFLib.StandardFonts.HelveticaBold);
      const fontReg = await masterPdf.embedFont(PDFLib.StandardFonts.Helvetica);

      page.drawText('RECTO', { x: startX + cardW / 2 - 20, y: cardY + cardH + 15, size: 11, font, color: rgb(0, 48/255, 135/255) });
      page.drawText('VERSO', { x: startX + cardW + gap + cardW / 2 - 20, y: cardY + cardH + 15, size: 11, font, color: rgb(0, 48/255, 135/255) });

      page.drawText(`ISGI · Carte d'Étudiant · ${student.nom} ${student.prenom} · ${student.matricule}`, {
        x: startX,
        y: 35,
        size: 9,
        font: fontReg,
        color: rgb(0.4, 0.4, 0.4)
      });

      await new Promise(r => setTimeout(r, 20)); // Let UI breathe
    }

    const pdfBytes = await masterPdf.save();
    const pdfBase64 = Buffer.from(pdfBytes).toString('base64');

    progressOverlay.classList.add('hidden');

    if (window.electronAPI) {
      const result = await window.electronAPI.savePDFBatch({
        pdfBase64,
        batchName: `${filiere.replace(/\s+/g,'_')}_${annee.replace(' - ','_')}`
      });
      if (result.success) toast(`${batchStudents.length} badges générés → ${result.filePath}`, 'success');
    } else {
      const link = document.createElement('a');
      link.href = `data:application/pdf;base64,${pdfBase64}`;
      link.download = `Badges_Lot.pdf`;
      link.click();
      toast(`${batchStudents.length} badges générés !`, 'success');
    }
  } catch (e) {
    console.error(e);
    progressOverlay.classList.add('hidden');
    toast('Erreur lors de la génération en lot', 'error');
  }
}

// ══ VIDÉOS (publique / classe) ════════════════════════

const ISGI_STRUCTURED_CLASSES = [
  { id: 'L1_IG', nom: 'Licence 1 — Informatique de Gestion (L1-IG)', filiere: 'Informatique de Gestion', niveau: 'Licence 1' },
  { id: 'L2_IG', nom: 'Licence 2 — Informatique de Gestion (L2-IG)', filiere: 'Informatique de Gestion', niveau: 'Licence 2' },
  { id: 'L3_IG', nom: 'Licence 3 — Informatique de Gestion (L3-IG)', filiere: 'Informatique de Gestion', niveau: 'Licence 3' },
  { id: 'BTS1_IG', nom: 'BTS 1 — Informatique de Gestion (BTS1-IG)', filiere: 'Informatique de Gestion', niveau: 'BTS 1' },
  { id: 'BTS2_IG', nom: 'BTS 2 — Informatique de Gestion (BTS2-IG)', filiere: 'Informatique de Gestion', niveau: 'BTS 2' },
  { id: 'L1_RT', nom: 'Licence 1 — Réseaux & Télécommunications (L1-RT)', filiere: 'Réseaux et Télécommunications', niveau: 'Licence 1' },
  { id: 'L2_RT', nom: 'Licence 2 — Réseaux & Télécommunications (L2-RT)', filiere: 'Réseaux et Télécommunications', niveau: 'Licence 2' },
  { id: 'L3_RT', nom: 'Licence 3 — Réseaux & Télécommunications (L3-RT)', filiere: 'Réseaux et Télécommunications', niveau: 'Licence 3' },
  { id: 'BTS1_FC', nom: 'BTS 1 — Finance & Comptabilité (BTS1-FC)', filiere: 'Finance et Comptabilité', niveau: 'BTS 1' },
  { id: 'BTS2_FC', nom: 'BTS 2 — Finance & Comptabilité (BTS2-FC)', filiere: 'Finance et Comptabilité', niveau: 'BTS 2' },
  { id: 'L1_FC', nom: 'Licence 1 — Finance & Comptabilité (L1-FC)', filiere: 'Finance et Comptabilité', niveau: 'Licence 1' },
  { id: 'L2_FC', nom: 'Licence 2 — Finance & Comptabilité (L2-FC)', filiere: 'Finance et Comptabilité', niveau: 'Licence 2' },
  { id: 'L3_FC', nom: 'Licence 3 — Finance & Comptabilité (L3-FC)', filiere: 'Finance et Comptabilité', niveau: 'Licence 3' },
  { id: 'L1_MC', nom: 'Licence 1 — Marketing & Commerce (L1-MC)', filiere: 'Marketing et Commerce', niveau: 'Licence 1' },
  { id: 'L2_MC', nom: 'Licence 2 — Marketing & Commerce (L2-MC)', filiere: 'Marketing et Commerce', niveau: 'Licence 2' },
  { id: 'L3_MC', nom: 'Licence 3 — Marketing & Commerce (L3-MC)', filiere: 'Marketing et Commerce', niveau: 'Licence 3' },
  { id: 'L1_GE', nom: 'Licence 1 — Gestion des Entreprises (L1-GE)', filiere: 'Gestion des Entreprises', niveau: 'Licence 1' },
  { id: 'L2_GE', nom: 'Licence 2 — Gestion des Entreprises (L2-GE)', filiere: 'Gestion des Entreprises', niveau: 'Licence 2' },
  { id: 'L3_GE', nom: 'Licence 3 — Gestion des Entreprises (L3-GE)', filiere: 'Gestion des Entreprises', niveau: 'Licence 3' },
  { id: 'L1_DA', nom: 'Licence 1 — Droit des Affaires (L1-DA)', filiere: 'Droit des Affaires', niveau: 'Licence 1' },
  { id: 'L2_DA', nom: 'Licence 2 — Droit des Affaires (L2-DA)', filiere: 'Droit des Affaires', niveau: 'Licence 2' },
  { id: 'L3_DA', nom: 'Licence 3 — Droit des Affaires (L3-DA)', filiere: 'Droit des Affaires', niveau: 'Licence 3' },
  { id: 'M1_INFO', nom: 'Master 1 — Informatique & Systèmes (M1-INFO)', filiere: 'Informatique', niveau: 'Master 1' },
  { id: 'M2_INFO', nom: 'Master 2 — Informatique & Systèmes (M2-INFO)', filiere: 'Informatique', niveau: 'Master 2' },
];

let currentVideoTab = 'publique';
let currentVideoFilter = 'all';
let currentVisibilite = 'publique';
let selectedClasseObj = null;

function initClassDropdowns() {
  const modalSel = document.getElementById('video-classe-select');
  const filterSel = document.getElementById('filter-classe');

  if (modalSel && modalSel.options.length <= 1) {
    ISGI_STRUCTURED_CLASSES.forEach(c => {
      const opt = document.createElement('option');
      opt.value = c.id;
      opt.textContent = c.nom;
      modalSel.appendChild(opt);
    });
  }

  if (filterSel && filterSel.options.length <= 1) {
    ISGI_STRUCTURED_CLASSES.forEach(c => {
      const opt = document.createElement('option');
      opt.value = c.id;
      opt.textContent = c.nom;
      filterSel.appendChild(opt);
    });
  }
}

function onVideoClasseSelectChange(val) {
  const found = ISGI_STRUCTURED_CLASSES.find(c => c.id === val);
  selectedClasseObj = found || null;
  const f = document.getElementById('video-filiere');
  const n = document.getElementById('video-niveau');
  if (found) {
    if (f) f.value = found.filiere;
    if (n) n.value = found.niveau;
  }
}

function switchVideoTab(tab, btn) {
  currentVideoTab = tab;
  document.querySelectorAll('.vtab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  const cf = document.getElementById('classeFilters');
  if (cf) cf.classList.toggle('hidden', tab !== 'classe');
  initClassDropdowns();
  renderVideoGrid();
}

function setVisibility(type) {
  currentVisibilite = type;
  ['publique', 'classe'].forEach(c => {
    const card = document.getElementById('viscard-' + c);
    if (card) card.classList.toggle('active', c === type);
  });
  const ctf = document.getElementById('classeTargetFields');
  if (ctf) ctf.classList.toggle('hidden', type !== 'classe');
  if (type === 'classe') initClassDropdowns();
}

function showAddVideoModal() {
  document.getElementById('addVideoModal').classList.remove('hidden');
  currentVisibilite = 'publique';
  setVisibility('publique');
  initClassDropdowns();
}

function closeAddVideoModal() {
  document.getElementById('addVideoModal').classList.add('hidden');
  currentVideoPath = null;
  currentThumbBase64 = null;
  selectedClasseObj = null;
  const t = document.getElementById('video-titre'); if (t) t.value = '';
  const d = document.getElementById('video-desc'); if (d) d.value = '';
  const cs = document.getElementById('video-classe-select'); if (cs) cs.value = '';
  const f = document.getElementById('video-filiere'); if (f) f.value = '';
  const n = document.getElementById('video-niveau'); if (n) n.value = '';
  const fl = document.getElementById('videoFileLabel');
  if (fl) fl.textContent = 'Cliquer pour choisir un fichier vidéo';
  const tl = document.getElementById('thumbLabel');
  if (tl) tl.textContent = '📷 Choisir une image de couverture';
}

async function selectVideoFile() {
  if (!window.electronAPI) { toast('Desktop requis', 'info'); return; }
  const path = await window.electronAPI.selectVideo();
  if (!path) return;
  currentVideoPath = path;
  const name = path.split('\\').pop();
  document.getElementById('videoFileLabel').textContent = `✓ ${name}`;
}

async function selectThumbnail() {
  if (!window.electronAPI) return;
  const result = await window.electronAPI.selectPhoto();
  if (!result) return;
  currentThumbBase64 = `data:image/${result.ext};base64,${result.base64}`;
  document.getElementById('thumbLabel').textContent = '✓ Miniature sélectionnée';
}

function addVideo() {
  const titre = document.getElementById('video-titre').value.trim();
  const desc = document.getElementById('video-desc').value.trim();
  const categorie = document.getElementById('video-categorie').value;

  if (!titre) { toast('Le titre est requis', 'error'); return; }
  if (!currentVideoPath) { toast('Sélectionnez un fichier vidéo', 'error'); return; }

  let classe_id = null;
  let classe_nom = null;
  let classe_filiere = null;
  let classe_niveau = null;

  if (currentVisibilite === 'classe') {
    const csVal = document.getElementById('video-classe-select')?.value;
    const found = ISGI_STRUCTURED_CLASSES.find(c => c.id === csVal);
    classe_filiere = document.getElementById('video-filiere')?.value || (found ? found.filiere : null);
    classe_niveau  = document.getElementById('video-niveau')?.value  || (found ? found.niveau : null);

    if (found) {
      classe_id = found.id;
      classe_nom = found.nom;
    } else if (classe_filiere && classe_niveau) {
      classe_id = classe_filiere.substring(0, 3).toUpperCase() + '_' + classe_niveau.replace(/\s+/g, '');
      classe_nom = `${classe_niveau} — ${classe_filiere}`;
    } else {
      toast('Veuillez sélectionner la classe destinataire de la vidéo', 'error');
      return;
    }
  }

  const video = {
    id: Date.now().toString(),
    titre, desc, categorie,
    visibilite: currentVisibilite,
    classe_id,
    classe_nom,
    classe_filiere,
    classe_niveau,
    classe_label: currentVisibilite === 'publique'
      ? '🌐 Publique'
      : ('🎓 ' + (classe_nom || `${classe_filiere} — ${classe_niveau}`)),
    path: currentVideoPath,
    thumb: currentThumbBase64,
    dateAjout: new Date().toISOString(),
    publie_par_nom: 'Service Informatique',
    vues: 0,
  };

  videoDB.unshift(video);
  localStorage.setItem('isgi_videos', JSON.stringify(videoDB));

  // Sync Supabase en tâche de fond si configuré
  if (appConfig.supabaseUrl && appConfig.supabaseKey) {
    try {
      fetch(`${appConfig.supabaseUrl}/rest/v1/videos`, {
        method: 'POST',
        headers: {
          'apikey': appConfig.supabaseKey,
          'Authorization': `Bearer ${appConfig.supabaseKey}`,
          'Content-Type': 'application/json',
          'Prefer': 'return=minimal'
        },
        body: JSON.stringify({
          titre: video.titre,
          description: video.desc,
          categorie: video.categorie,
          visibilite: video.visibilite,
          classe_id: video.classe_id,
          classe_nom: video.classe_nom,
          classe_filiere: video.classe_filiere,
          classe_niveau: video.classe_niveau,
          publie_par_nom: video.publie_par_nom,
          statut: 'publie'
        })
      }).catch(err => console.warn('Supabase videos sync:', err));
    } catch {}
  }

  closeAddVideoModal();
  // Basculer sur le bon onglet
  const tabBtn = document.querySelector('[data-tab="' + currentVisibilite + '"]');
  if (tabBtn) switchVideoTab(currentVisibilite, tabBtn);
  else renderVideoGrid();
  updateDashboardStats();
  updateVideoTabCounts();
  const where = currentVisibilite === 'publique' ? 'bibliothèque publique' : ('classe ' + (classe_nom || classe_filiere));
  toast('Vidéo "' + titre + '" publiée pour la ' + where, 'success');
}

function updateVideoTabCounts() {
  const cp = document.getElementById('countPublique');
  const cc = document.getElementById('countClasse');
  if (cp) cp.textContent = videoDB.filter(v => v.visibilite === 'publique').length;
  if (cc) cc.textContent = videoDB.filter(v => v.visibilite === 'classe').length;
}

function filterVideos(cat, btn) {
  currentVideoFilter = cat;
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  renderVideoGrid();
}

function renderVideoGrid() {
  const grid = document.getElementById('videoGrid');
  if (!grid) return;
  initClassDropdowns();
  updateVideoTabCounts();

  // Filtre onglet publique/classe
  let list = videoDB.filter(v => (v.visibilite || 'publique') === currentVideoTab);

  // Filtre catégorie
  if (currentVideoFilter !== 'all') list = list.filter(v => v.categorie === currentVideoFilter);

  // Filtres classe si onglet = classe
  if (currentVideoTab === 'classe') {
    const fc = document.getElementById('filter-classe')?.value || '';
    const ff = document.getElementById('filter-filiere')?.value || '';
    const fn = document.getElementById('filter-niveau')?.value  || '';
    if (fc) list = list.filter(v => v.classe_id === fc || (v.classe_nom && v.classe_nom.includes(fc)));
    if (ff) list = list.filter(v => v.classe_filiere === ff);
    if (fn) list = list.filter(v => v.classe_niveau  === fn);
  }

  if (!list.length) {
    const msg = currentVideoTab === 'publique'
      ? 'Aucune vidéo dans la bibliothèque publique'
      : 'Aucune vidéo trouvée pour cette classe';
    grid.innerHTML = `
      <div class="empty-state">
        <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
        <p>${msg}</p>
        <button class="btn btn-primary" onclick="showAddVideoModal()">Publier une vidéo</button>
      </div>`;
    return;
  }

  grid.innerHTML = list.map(v => `
    <div class="video-card" onclick="playVideo('${v.id}')">
      <div class="video-thumbnail">
        ${v.thumb ? '<img src="' + v.thumb + '" alt="' + v.titre + '" />' : ''}
        <div class="video-play-btn">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="white"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        </div>
        <div class="video-vis-badge ${v.visibilite || 'publique'}">
          ${v.visibilite === 'classe' ? '🎓 ' + (v.classe_id || 'Classe') : '🌐 Public'}
        </div>
      </div>
      <div class="video-info">
        <div class="video-title">${v.titre}</div>
        <div class="video-meta">
          <span class="video-cat">${v.categorie}</span>
          ${v.vues ? '<span>' + v.vues + ' vue' + (v.vues > 1 ? 's' : '') + '</span>' : ''}
        </div>
        ${v.classe_nom || v.classe_label ? '<div class="video-classe-tag">🎓 ' + (v.classe_nom || v.classe_label) + '</div>' : ''}
        <div class="video-date">${new Date(v.dateAjout).toLocaleDateString('fr-FR')}</div>
      </div>
      <div class="video-card-actions">
        <button class="btn btn-sm" style="background:rgba(239,68,68,0.1);color:#ef4444"
          onclick="event.stopPropagation();deleteVideo('${v.id}')" title="Supprimer">✕</button>
      </div>
    </div>
  `).join('');
}

function deleteVideo(id) {
  if (!confirm('Supprimer cette vidéo de la bibliothèque ?')) return;
  videoDB = videoDB.filter(v => v.id !== id);
  localStorage.setItem('isgi_videos', JSON.stringify(videoDB));
  renderVideoGrid();
  updateDashboardStats();
  toast('Vidéo supprimée', 'info');
}

async function playVideo(id) {
  const video = videoDB.find(v => v.id === id);
  if (!video) return;

  const modal = document.getElementById('videoPlayerModal');
  const player = document.getElementById('videoPlayer');
  document.getElementById('videoPlayerTitle').textContent = video.titre;
  document.getElementById('videoPlayerMeta').textContent =
    video.categorie + ' · ' + (video.classe_label || '🌐 Publique') +
    ' · ' + new Date(video.dateAjout).toLocaleDateString('fr-FR');

  if (video.path) {
    player.src = video.path.startsWith('http') ? video.path : 'file://' + video.path;
  }

  modal.classList.remove('hidden');
  player.play().catch(() => {});
}

function closeVideoPlayer() {
  const player = document.getElementById('videoPlayer');
  player.pause();
  player.src = '';
  document.getElementById('videoPlayerModal').classList.add('hidden');
}

// ══ ÉTUDIANTS ════════════════════════════════════════

async function syncEtudiants() {
  const url = appConfig.supabaseUrl;
  const key = appConfig.supabaseKey;

  if (!url || !key) {
    toast('Configurez d\'abord la connexion Supabase dans Paramètres', 'info');
    navigate('parametres');
    return;
  }

  showLoading('Synchronisation avec Supabase...');
  try {
    const response = await fetch(`${url}/rest/v1/etudiants?select=*&order=nom.asc`, {
      headers: {
        'apikey': key,
        'Authorization': `Bearer ${key}`,
        'Content-Type': 'application/json',
      }
    });

    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const data = await response.json();

    etudiantsDB = data.map(e => ({
      id: e.id,
      nom: e.nom || e.last_name || '',
      prenom: e.prenom || e.first_name || '',
      matricule: e.matricule || e.numero_isgi || `ISGI-${e.id}`,
      filiere: e.filiere || '',
      niveau: e.niveau || '',
      lieu: e.lieu_naissance || '',
      photo: e.photo_url || null,
    }));

    localStorage.setItem('isgi_etudiants', JSON.stringify(etudiantsDB));
    renderEtudiantsTable();
    renderBadgeStudioStudents();
    updateDashboardStats();
    toast(`${etudiantsDB.length} étudiant(s) synchronisés`, 'success');
  } catch (e) {
    console.warn('Supabase sync error, trying local endpoint:', e);
    // Fallback to local WAMP API
    try {
      const localRes = await fetch('http://172.16.202.33/isgi_system/ajax/get_all_etudiants.php');
      const localData = await localRes.json();
      if (localData && localData.etudiants && localData.etudiants.length > 0) {
        etudiantsDB = localData.etudiants;
        localStorage.setItem('isgi_etudiants', JSON.stringify(etudiantsDB));
        renderEtudiantsTable();
        renderBadgeStudioStudents();
        updateDashboardStats();
        toast(`${etudiantsDB.length} étudiant(s) chargés depuis la base locale`, 'success');
        return;
      }
    } catch (e2) {}
    toast(`Erreur de synchronisation: ${e.message}`, 'error');
  } finally {
    hideLoading();
  }
}

function renderEtudiantsTable(filter = '') {
  const tbody = document.getElementById('etudiantTableBody');
  let list = filter
    ? etudiantsDB.filter(e => `${e.nom} ${e.prenom} ${e.matricule}`.toLowerCase().includes(filter.toLowerCase()))
    : etudiantsDB;

  if (!list.length) {
    tbody.innerHTML = '<tr><td colspan="7" class="empty-cell">Cliquer sur "Sync Supabase" pour charger les étudiants</td></tr>';
    return;
  }

  tbody.innerHTML = list.map(e => {
    const hasBadge = badgeDB.some(b => b.matricule === e.matricule);
    return `
      <tr>
        <td>
          <div class="rb-avatar">${(e.nom||'?')[0]}${(e.prenom||'?')[0]}</div>
        </td>
        <td><code style="color:var(--orange);font-size:12px">${e.matricule}</code></td>
        <td><strong>${e.nom}</strong> ${e.prenom}</td>
        <td>${e.filiere || '—'}</td>
        <td>${e.niveau || '—'}</td>
        <td>
          <span style="padding:3px 8px;border-radius:4px;font-size:11px;font-weight:600;
            ${hasBadge ? 'background:rgba(16,185,129,0.15);color:#10b981' : 'background:rgba(239,68,68,0.15);color:#ef4444'}">
            ${hasBadge ? '✓ Généré' : '✗ Non généré'}
          </span>
        </td>
        <td>
          <button class="btn btn-sm btn-outline" onclick="quickBadge('${e.id || e.matricule}')">
            ↓ Badge
          </button>
        </td>
      </tr>`;
  }).join('');
}

function filterEtudiants() {
  renderEtudiantsTable(document.getElementById('etudiantSearch').value);
}

function quickBadge(id) {
  const e = etudiantsDB.find(s => s.id === id || s.matricule === id);
  if (!e) return;
  navigate('badge-create');
  setTimeout(() => {
    selectStudentForBadge(e.id || e.matricule);
  }, 100);
}

// ══ PARAMÈTRES ═══════════════════════════════════════

async function saveSupabaseConfig() {
  appConfig.supabaseUrl = document.getElementById('supabase-url').value.trim();
  appConfig.supabaseKey = document.getElementById('supabase-key').value.trim();
  localStorage.setItem('isgi_config', JSON.stringify(appConfig));

  const resultEl = document.getElementById('supabase-test-result');
  resultEl.classList.remove('hidden', 'success', 'error');

  try {
    const response = await fetch(`${appConfig.supabaseUrl}/rest/v1/`, {
      headers: { 'apikey': appConfig.supabaseKey }
    });
    if (response.ok || response.status === 200 || response.status === 400) {
      resultEl.classList.add('success');
      resultEl.textContent = '✓ Connexion réussie ! Configuration sauvegardée.';
      checkSupabaseConnection();
    } else throw new Error(`HTTP ${response.status}`);
  } catch (e) {
    resultEl.classList.add('error');
    resultEl.textContent = `✗ Erreur : ${e.message}`;
  }
}

function saveAppParams() {
  appConfig.directeur = document.getElementById('param-directeur').value.trim();
  appConfig.anneeDefaut = document.getElementById('param-annee').value;
  localStorage.setItem('isgi_config', JSON.stringify(appConfig));
  toast('Paramètres sauvegardés', 'success');
}

async function chooseOutputDir() {
  if (!window.electronAPI) return;
  const dir = await window.electronAPI.selectOutputDir();
  if (dir) {
    appConfig.outputDir = dir;
    localStorage.setItem('isgi_config', JSON.stringify(appConfig));
    document.getElementById('param-output-dir').value = dir;
  }
}

async function checkSupabaseConnection() {
  const dot = document.getElementById('statusDot');
  const text = document.getElementById('statusText');

  if (!appConfig.supabaseUrl || !appConfig.supabaseKey) {
    dot.className = 'status-dot offline';
    text.textContent = 'Non configuré';
    return;
  }

  try {
    const r = await fetch(`${appConfig.supabaseUrl}/rest/v1/`, {
      headers: { 'apikey': appConfig.supabaseKey }
    });
    if (r.ok || r.status === 400) {
      dot.className = 'status-dot online';
      text.textContent = 'Connecté';
    } else throw new Error();
  } catch {
    dot.className = 'status-dot offline';
    text.textContent = 'Hors ligne';
  }
}

// ══ UTILITIES ════════════════════════════════════════

function toast(message, type = 'info') {
  const container = document.getElementById('toastContainer');
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  const icons = { success: '✓', error: '✗', info: 'ℹ' };
  el.innerHTML = `<span>${icons[type]}</span><span>${message}</span>`;
  container.appendChild(el);
  setTimeout(() => el.remove(), 4000);
}

function showLoading(text = 'Chargement...') {
  document.getElementById('loadingText').textContent = text;
  document.getElementById('loadingOverlay').classList.remove('hidden');
}

function hideLoading() {
  document.getElementById('loadingOverlay').classList.add('hidden');
}
