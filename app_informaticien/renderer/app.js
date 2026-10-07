// ═══════════════════════════════════════════════════════
//  ISGI Informaticien — Application Logic
//  Badge Generation | Vidéos | Étudiants | Supabase Sync
// ═══════════════════════════════════════════════════════

// ── State ──────────────────────────────────────────────
let currentPhoto = null; // { base64: '...', ext: 'jpg' }
let badgeDB = [];
let videoDB = [];
let etudiantsDB = [];
let academicClasses = [];
let currentVideoPath = null;
let currentThumbBase64 = null;
let batchStudents = [];
const badgeTextFields = {
  nom:      { label: 'Nom',              x: 360, y: 1077, maxWidth: 600, fontSize: 26, fontFamily: 'Arial', bold: true, color: '#ffffff', align: 'left' },
  prenom:   { label: 'Prénom',           x: 360, y: 1135, maxWidth: 600, fontSize: 26, fontFamily: 'Arial', bold: true, color: '#ffffff', align: 'left' },
  lieu:     { label: 'Lieu de naissance',x: 360, y: 1195, maxWidth: 600, fontSize: 24, fontFamily: 'Arial', bold: true, color: '#ffffff', align: 'left' },
  filiere:  { label: 'Filière',          x: 360, y: 1253, maxWidth: 600, fontSize: 24, fontFamily: 'Arial', bold: true, color: '#ffffff', align: 'left' },
  niveau:   { label: 'Niveau',           x: 360, y: 1314, maxWidth: 380, fontSize: 24, fontFamily: 'Arial', bold: true, color: '#ffffff', align: 'left' },
  matricule:{ label: 'Matricule',        x: 535, y: 1417, maxWidth: 410, fontSize: 30, fontFamily: 'Arial', bold: true, color: '#ffffff', align: 'center' }
};
const badgePhotoFrame = { x: 322, y: 400, width: 426, height: 452 };
let badgePhotoTransform = { zoom: 1, offsetX: 0, offsetY: 0 };
let selectedBadgeElement = 'nom';
let badgeDragState = null;
const sharedSupabaseConfig = {
  supabaseUrl: 'https://vbdhmgrysrerlmgumafx.supabase.co',
  supabaseKey: 'sb_publishable_sqJUSK-p5mF2Acy_bhxhAQ_nt_t6Fax'
};
let appConfig = {
  ...JSON.parse(localStorage.getItem('isgi_config') || '{}'),
  ...sharedSupabaseConfig
};

// Studio State
let activeStudentId = null;
let studioFilter = 'all'; // 'all', 'no-photo', 'has-photo'
let activeBadgeSide = 'recto'; // 'recto' or 'verso'
let phonePollInterval = null;
let currentPhoneSessionId = null;
let webcamStream = null;

// Preload Official Templates
const templateRectoImg = new Image();
templateRectoImg.crossOrigin = 'anonymous';
templateRectoImg.src = './images/badge_recto.png';
const templateVersoImg = new Image();
templateVersoImg.crossOrigin = 'anonymous';
templateVersoImg.src = './images/badge_verso.png';
let templateImagesLoaded = false;

// Custom templates state — data URLs loaded from userData at startup
let customTemplates = { recto: null, verso: null };

async function loadOfficialTemplates() {
  if (templateImagesLoaded) return;
  if (window.electronAPI && window.electronAPI.getTemplateImages) {
    try {
      const templates = await window.electronAPI.getTemplateImages();
      if (templates?.recto) {
        templateRectoImg.src = templates.recto;
        customTemplates.recto = templates.recto;
        // Sync settings page preview & status
        const prevRecto = document.getElementById('previewTemplateRecto');
        if (prevRecto) prevRecto.src = templates.recto;
        const statusRecto = document.getElementById('statusBadgeRecto');
        if (statusRecto) { statusRecto.textContent = 'Modèle Personnalisé'; statusRecto.className = 'template-status-badge status-custom'; }
        const btnResetRecto = document.getElementById('btnResetTemplateRecto');
        if (btnResetRecto) btnResetRecto.style.display = '';
        document.getElementById('templateBoxRecto')?.classList.add('has-custom');
      }
      if (templates?.verso) {
        templateVersoImg.src = templates.verso;
        customTemplates.verso = templates.verso;
        const versoEl = document.getElementById('badgeOfficialVersoImg');
        if (versoEl) versoEl.src = templates.verso;
        // Sync settings page preview & status
        const prevVerso = document.getElementById('previewTemplateVerso');
        if (prevVerso) prevVerso.src = templates.verso;
        const statusVerso = document.getElementById('statusBadgeVerso');
        if (statusVerso) { statusVerso.textContent = 'Modèle Personnalisé'; statusVerso.className = 'template-status-badge status-custom'; }
        const btnResetVerso = document.getElementById('btnResetTemplateVerso');
        if (btnResetVerso) btnResetVerso.style.display = '';
        document.getElementById('templateBoxVerso')?.classList.add('has-custom');
      }
      templateImagesLoaded = true;
    } catch (e) {
      console.warn('Fallback aux images de templates locales:', e);
      templateImagesLoaded = true;
    }
  } else {
    templateImagesLoaded = true;
  }
}

// ── Gestion Cache Photos Étudiants (persistance hors-ligne & redémarrage) ──
function getLocalStudentPhotos() {
  try {
    return JSON.parse(localStorage.getItem('isgi_student_photos') || '{}');
  } catch {
    return {};
  }
}

function saveLocalStudentPhoto(student, base64) {
  if (!student || !base64) return;
  try {
    const photos = getLocalStudentPhotos();
    if (student.matricule) photos[student.matricule] = base64;
    if (student.id) photos[String(student.id)] = base64;
    localStorage.setItem('isgi_student_photos', JSON.stringify(photos));
  } catch (e) {
    console.warn('Erreur sauvegarde photo localStorage:', e);
  }
}

function removeLocalStudentPhoto(student) {
  if (!student) return;
  try {
    const photos = getLocalStudentPhotos();
    if (student.matricule) delete photos[student.matricule];
    if (student.id) delete photos[String(student.id)];
    localStorage.setItem('isgi_student_photos', JSON.stringify(photos));
  } catch (e) {
    console.warn('Erreur suppression photo localStorage:', e);
  }
}

// NOTE: photos are synchronisées vers Supabase Storage (voir updateStudentPhotoInSupabase)
// Le localStorage est uniquement un cache de session pour affichage sans rechargement

// ── Init ───────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  // Initialiser le thème (clair ou sombre)
  initTheme();

  // Charger les modèles officiels en Base64 pour éviter de souiller le canvas
  await loadOfficialTemplates();

  // App version / Web browser mode
  if (window.electronAPI) {
    const v = await window.electronAPI.getVersion();
    document.getElementById('appVersion').textContent = `v${v}`;
    document.getElementById('aboutVersion').textContent = v;
  } else {
    document.querySelectorAll('.desktop-window-control').forEach(el => el.style.display = 'none');
  }

  // Load local app preferences; the ISGI Supabase project is shared with the other apps.
  if (appConfig.outputDir) document.getElementById('param-output-dir').value = appConfig.outputDir;

  // Initialize official badge studio
  initBadgeStudio();

  // Load dashboard stats
  updateDashboardStats();
  renderRecentBadges();
  renderVideoGrid();

  // Check connection
  checkSupabaseConnection();
  await restoreAuthSession();
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
  const recent = badgeDB.slice(0, 5);

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
  initBadgeEditor();
  renderBadgeStudioStudents();

  // Select only students loaded from the academic database.
  if (!activeStudentId && etudiantsDB.length > 0) {
    selectStudentForBadge(etudiantsDB[0].id || etudiantsDB[0].matricule);
  } else {
    renderOfficialBadgeCanvas();
  }
}

function initBadgeEditor() {
  const canvas = document.getElementById('badgeOfficialCanvas');
  if (!canvas || canvas.dataset.editorReady) return;
  canvas.dataset.editorReady = 'true';
  canvas.addEventListener('pointerdown', startBadgeElementDrag);
  canvas.addEventListener('pointermove', moveBadgeElementDrag);
  canvas.addEventListener('pointerup', finishBadgeElementDrag);
  canvas.addEventListener('pointercancel', finishBadgeElementDrag);
  syncBadgeEditorControls();
}

function setBadgeEditorTarget(target) {
  selectedBadgeElement = target;
  syncBadgeEditorControls();
  renderOfficialBadgeCanvas();
}

function syncBadgeEditorControls() {
  const targetSelect = document.getElementById('badge-editor-target');
  const textTools = document.getElementById('badge-text-tools');
  const photoTools = document.getElementById('badge-photo-tools');
  const isText = Object.hasOwn(badgeTextFields, selectedBadgeElement);
  if (targetSelect) targetSelect.value = selectedBadgeElement;
  textTools?.classList.toggle('hidden', !isText);
  photoTools?.classList.toggle('hidden', selectedBadgeElement !== 'photo');
  if (!isText) return;

  const field = badgeTextFields[selectedBadgeElement];
  document.getElementById('badge-editor-font').value = field.fontFamily;
  document.getElementById('badge-editor-size').value = field.fontSize;
  document.getElementById('badge-editor-color').value = field.color;
  document.getElementById('badge-editor-bold').checked = field.bold;
  document.getElementById('badge-editor-align').value = field.align;
}

function updateSelectedBadgeTextStyle() {
  const field = badgeTextFields[selectedBadgeElement];
  if (!field) return;
  field.fontFamily = document.getElementById('badge-editor-font').value;
  field.fontSize = Math.max(10, Math.min(72, Number(document.getElementById('badge-editor-size').value) || field.fontSize));
  field.color = document.getElementById('badge-editor-color').value;
  field.bold = document.getElementById('badge-editor-bold').checked;
  field.align = document.getElementById('badge-editor-align').value;
  renderOfficialBadgeCanvas();
}

function moveSelectedBadgeElement(direction) {
  const field = badgeTextFields[selectedBadgeElement];
  if (!field) return;
  const step = 10;
  if (direction === 'left') field.x -= step;
  if (direction === 'right') field.x += step;
  if (direction === 'up') field.y -= step;
  if (direction === 'down') field.y += step;
  renderOfficialBadgeCanvas();
}

function updateBadgePhotoZoom(value) {
  badgePhotoTransform.zoom = Math.max(1, Math.min(2.5, Number(value) || 1));
  renderOfficialBadgeCanvas();
}

function resetBadgePhotoTransform() {
  badgePhotoTransform = { zoom: 1, offsetX: 0, offsetY: 0 };
  const zoomInput = document.getElementById('badge-photo-zoom');
  if (zoomInput) zoomInput.value = '1';
  renderOfficialBadgeCanvas();
}

function getBadgeCanvasPoint(event) {
  const rect = event.currentTarget.getBoundingClientRect();
  return {
    x: (event.clientX - rect.left) * event.currentTarget.width / rect.width,
    y: (event.clientY - rect.top) * event.currentTarget.height / rect.height
  };
}

function getBadgeTextBounds(ctx, field, text) {
  const font = `${field.bold ? 'bold ' : ''}${field.fontSize}px "${field.fontFamily}", Arial, sans-serif`;
  ctx.font = font;
  const width = Math.min(field.maxWidth, ctx.measureText(text || field.label).width);
  const left = field.align === 'center' ? field.x - width / 2
    : field.align === 'right' ? field.x - width
      : field.x;
  return { left, top: field.y - field.fontSize * 0.8, width, height: field.fontSize * 1.4 };
}

function hitTestBadgeElement(point) {
  const photo = badgePhotoFrame;
  if (currentPhoto && point.x >= photo.x && point.x <= photo.x + photo.width &&
      point.y >= photo.y && point.y <= photo.y + photo.height) return 'photo';

  const canvas = document.getElementById('badgeOfficialCanvas');
  const ctx = canvas.getContext('2d');
  const values = getBadgeStudentFromForm();
  const fields = Object.keys(badgeTextFields).reverse();
  for (const name of fields) {
    const field = badgeTextFields[name];
    const text = name === 'matricule' && values.matricule
      ? `N°ISGI : ${values.matricule}`
      : values[name] || '';
    const bounds = getBadgeTextBounds(ctx, field, text);
    if (point.x >= bounds.left - 8 && point.x <= bounds.left + bounds.width + 8 &&
        point.y >= bounds.top - 5 && point.y <= bounds.top + bounds.height + 5) return name;
  }
  return null;
}

function startBadgeElementDrag(event) {
  const point = getBadgeCanvasPoint(event);
  const hit = hitTestBadgeElement(point);
  if (!hit) return;
  selectedBadgeElement = hit;
  syncBadgeEditorControls();
  badgeDragState = {
    pointerId: event.pointerId,
    x: point.x,
    y: point.y,
    originX: hit === 'photo' ? badgePhotoTransform.offsetX : badgeTextFields[hit].x,
    originY: hit === 'photo' ? badgePhotoTransform.offsetY : badgeTextFields[hit].y
  };
  event.currentTarget.setPointerCapture(event.pointerId);
  event.currentTarget.classList.add('badge-canvas-dragging');
  renderOfficialBadgeCanvas();
}

function moveBadgeElementDrag(event) {
  if (!badgeDragState || event.pointerId !== badgeDragState.pointerId) return;
  const point = getBadgeCanvasPoint(event);
  if (selectedBadgeElement === 'photo') {
    badgePhotoTransform.offsetX = badgeDragState.originX + point.x - badgeDragState.x;
    badgePhotoTransform.offsetY = badgeDragState.originY + point.y - badgeDragState.y;
  } else {
    const field = badgeTextFields[selectedBadgeElement];
    field.x = badgeDragState.originX + point.x - badgeDragState.x;
    field.y = badgeDragState.originY + point.y - badgeDragState.y;
  }
  renderOfficialBadgeCanvas();
}

function finishBadgeElementDrag(event) {
  if (!badgeDragState || event.pointerId !== badgeDragState.pointerId) return;
  badgeDragState = null;
  event.currentTarget.classList.remove('badge-canvas-dragging');
  syncBadgeEditorControls();
  renderOfficialBadgeCanvas();
}

function getBadgeStudentFromForm() {
  return {
    nom: (document.getElementById('badge-nom')?.value || '').trim().toUpperCase(),
    prenom: (document.getElementById('badge-prenom')?.value || '').trim(),
    lieu: (document.getElementById('badge-lieu')?.value || '').trim(),
    filiere: (document.getElementById('badge-filiere')?.value || '').trim(),
    niveau: (document.getElementById('badge-niveau')?.value || '').trim(),
    matricule: (document.getElementById('badge-matricule')?.value || '').trim().toUpperCase()
  };
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
    listEl.innerHTML = '<div class="empty-state-sm">Aucun étudiant réel trouvé dans la base académique.</div>';
    return;
  }

  listEl.innerHTML = filtered.map(s => {
    const isAct = s.id === activeStudentId || s.matricule === document.getElementById('badge-matricule')?.value;
    const hasPhoto = !!s.photo;
    const rawSrc = s.photo ? (s.photo.startsWith('data:') || s.photo.startsWith('http') ? s.photo : `../${s.photo}`) : null;
    const photoSrc = rawSrc ? normalizeStorageUrl(rawSrc) : null;

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
  badgePhotoTransform = { zoom: 1, offsetX: 0, offsetY: 0 };
  const zoomInput = document.getElementById('badge-photo-zoom');
  if (zoomInput) zoomInput.value = '1';

  if (document.getElementById('badge-nom')) document.getElementById('badge-nom').value = student.nom || '';
  if (document.getElementById('badge-prenom')) document.getElementById('badge-prenom').value = student.prenom || '';
  if (document.getElementById('badge-matricule')) document.getElementById('badge-matricule').value = student.matricule || '';
  if (document.getElementById('badge-lieu')) document.getElementById('badge-lieu').value = student.lieu || '';
  if (document.getElementById('badge-filiere') && student.filiere) document.getElementById('badge-filiere').value = student.filiere;
  if (document.getElementById('badge-niveau') && student.niveau) document.getElementById('badge-niveau').value = student.niveau;

  // Restaurer depuis le cache local si la photo n'est pas encore attachée en mémoire
  if (!student.photo) {
    const cached = getLocalStudentPhotos();
    student.photo = (student.matricule && cached[student.matricule]) || (student.id && cached[String(student.id)]) || null;
  }

  if (student.photo) {
    setStudentPhotoData(student.photo, false, false);
  } else {
    clearStudentPhoto(false, false);
  }

  renderBadgeStudioStudents();
  renderOfficialBadgeCanvas();
}

// ── Photo Handling ─────────────────────────────────────
async function selectStudentPhoto() {
  if (!window.electronAPI) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/jpeg,image/png,image/webp';
    input.onchange = async (e) => {
      const file = e.target.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = async (evt) => {
        const saved = await setStudentPhotoData(evt.target.result);
        if (saved && !getCurrentBadgeStudent()) {
          toast('Photo ajoutée à l’aperçu du badge.', 'success');
        }
      };
      reader.readAsDataURL(file);
    };
    input.click();
    return;
  }

  const result = await window.electronAPI.selectPhoto();
  if (!result) return;
  const saved = await setStudentPhotoData(`data:image/${result.ext};base64,${result.base64}`);
  if (saved && !getCurrentBadgeStudent()) {
    toast('Photo ajoutée à l’aperçu du badge.', 'success');
  }
}

function getCurrentBadgeStudent(matricule = document.getElementById('badge-matricule')?.value) {
  return etudiantsDB.find(student =>
    student.matricule === matricule && (!activeStudentId || student.id === activeStudentId)
  );
}

function setStudentPhotoData(base64Data, notify = true, persist = true) {
  currentPhoto = {
    base64: base64Data,
    ext: 'jpeg'
  };

  const thumb = document.getElementById('photoThumbImg');
  const emptyIcon = document.getElementById('photoEmptyIcon');

  if (thumb && emptyIcon) {
    const rawThumb = base64Data.startsWith('data:') || base64Data.startsWith('http')
      ? base64Data
      : `data:image/jpeg;base64,${base64Data}`;
    thumb.src = normalizeStorageUrl(rawThumb);
    thumb.classList.remove('hidden');
    emptyIcon.classList.add('hidden');
  }

  const student = getCurrentBadgeStudent();
  if (student) {
    // Mettre à jour l'objet en mémoire et le cache de session
    student.photo = base64Data;
    saveLocalStudentPhoto(student, base64Data);
  }

  if (persist && student) {
    return updateStudentPhotoInSupabase(student, base64Data)
      .then((signedUrl) => {
        // Garder le base64Data localement : cela évite le canvas tainted (cross-origin)
        // et garantit que la photo reste visible après redémarrage de l'appli
        student.photo = base64Data;
        saveLocalStudentPhoto(student, base64Data);
        currentPhoto.base64 = base64Data;
        renderBadgeStudioStudents();
        renderOfficialBadgeCanvas();
        if (notify) toast('Photo enregistrée dans Supabase.', 'success');
        return true;
      })
      .catch(error => {
        console.warn('Synchronisation Supabase échouée — photo conservée en session:', error);
        renderBadgeStudioStudents();
        if (notify) toast(`Photo affichée mais non sauvegardée : ${error.message}`, 'error');
        return false;
      });
  }

  renderBadgeStudioStudents();
  renderOfficialBadgeCanvas();
  return Promise.resolve(true);
}

async function updateStudentPhotoInSupabase(student, dataUrl) {
  const previousPath = student.photoPath;
  let nextPath = null;
  let signedNextPhoto = null;

  if (dataUrl) {
    // 1. Redimensionner la photo à max 1600px
    const source = new Image();
    await new Promise((resolve, reject) => {
      source.onload = resolve;
      source.onerror = () => reject(new Error('La photo sélectionnée est illisible.'));
      source.src = dataUrl.startsWith('data:') || dataUrl.startsWith('http')
        ? dataUrl
        : `data:image/jpeg;base64,${dataUrl}`;
    });
    const scale = Math.min(1, 1600 / Math.max(source.naturalWidth || 1, source.naturalHeight || 1));
    const offCanvas = document.createElement('canvas');
    offCanvas.width = Math.round(source.naturalWidth * scale);
    offCanvas.height = Math.round(source.naturalHeight * scale);
    offCanvas.getContext('2d').drawImage(source, 0, 0, offCanvas.width, offCanvas.height);
    const imageBlob = await new Promise(resolve => offCanvas.toBlob(resolve, 'image/jpeg', 0.88));
    if (!imageBlob) throw new Error('La conversion de la photo a échoué.');

    // 2. Téléverser vers Supabase Storage (bucket : student-photos)
    nextPath = `photos/${student.id || student.matricule}/${Date.now()}.jpg`;
    const encodedPath = nextPath.split('/').map(encodeURIComponent).join('/');
    await supabaseRequest(`/storage/v1/object/student-photos/${encodedPath}`, {
      method: 'POST',
      headers: { 'Content-Type': 'image/jpeg', 'x-upsert': 'true' },
      body: imageBlob
    });

    // 3. Obtenir l'URL signée (1 heure)
    signedNextPhoto = await getSignedStorageUrl('student-photos', nextPath);
  }

  // 4. Mettre à jour la colonne photo_url dans la table etudiants
  await supabaseRequest(`/rest/v1/etudiants?id=eq.${student.id}`, {
    method: 'PATCH',
    headers: { Prefer: 'return=minimal' },
    body: JSON.stringify({ photo_url: nextPath })
  });

  student.photoPath = nextPath;
  student.photo = signedNextPhoto || null;

  // 5. Supprimer l'ancienne photo du Storage si elle a changé
  if (previousPath && previousPath !== nextPath) {
    try {
      await deleteStorageObjects('student-photos', [previousPath]);
    } catch (e) {
      console.warn('Nettoyage ancienne photo ignoré:', e);
    }
  }

  return signedNextPhoto;
}

function clearStudentPhoto(notify = true, persist = true) {
  currentPhoto = null;
  const thumb = document.getElementById('photoThumbImg');
  const emptyIcon = document.getElementById('photoEmptyIcon');

  if (thumb) {
    thumb.src = '';
    thumb.classList.add('hidden');
  }
  if (emptyIcon) emptyIcon.classList.remove('hidden');

  const student = getCurrentBadgeStudent();
  if (student) {
    student.photo = null;
    removeLocalStudentPhoto(student);
  }

  if (persist && student?.photoPath) {
    updateStudentPhotoInSupabase(student, null)
      .then(() => {
        renderBadgeStudioStudents();
        if (notify) toast('Photo supprimée de la base de données.', 'success');
      })
      .catch(error => {
        console.warn('Suppression Supabase non effectuée:', error);
      });
  } else if (notify) {
    toast(student ? 'Photo retirée du badge.' : 'Photo retirée du badge.', 'info');
  }

  renderBadgeStudioStudents();
  renderOfficialBadgeCanvas();
}

// ── Smartphone Studio QR Modal & Polling ───────────────
function startPhonePhotoSession() {
  const nom = (document.getElementById('badge-nom')?.value || '').trim();
  const prenom = (document.getElementById('badge-prenom')?.value || '').trim();
  const matricule = (document.getElementById('badge-matricule')?.value || '').trim();

  if (!matricule || !getCurrentBadgeStudent(matricule)) {
    toast('Sélectionnez un étudiant réel de la base avant la capture mobile.', 'info');
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

        const saved = await setStudentPhotoData(data.base64);

        if (statusText) {
          statusText.innerHTML = saved
            ? '<span style="color:#10b981;font-weight:700">✓ Photo reçue et enregistrée !</span>'
            : '<span style="color:#ef4444;font-weight:700">Photo reçue, mais non enregistrée dans la base.</span>';
        }
        if (!saved) return;

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

async function renderOfficialBadgeCanvas(canvas = document.getElementById('badgeOfficialCanvas'), showSelection = true) {
  if (!canvas) return;
  const student = {
    ...getBadgeStudentFromForm(),
    annee: (document.getElementById('badge-annee')?.value || '').trim()
  };
  await renderBadgeToCanvas(canvas, student, currentPhoto, { showSelection });
}

async function renderBadgeToCanvas(canvas, student, photoObj, {
  showSelection = false,
  photoTransform = badgePhotoTransform,
  textFields = badgeTextFields
} = {}) {
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

  // 2. Draw the photo crop in the template's photo frame.
  if (photoObj && photoObj.base64) {
    await drawRoundedPhoto(ctx, photoObj, badgePhotoFrame, photoTransform, 28);
  }

  // 3. Draw editable text layers at their configured positions.
  ctx.textBaseline = 'middle';
  const textBounds = {};
  for (const name of ['nom', 'prenom', 'lieu', 'filiere', 'niveau', 'matricule']) {
    const field = textFields[name];
    if (!field) continue;
    const value = name === 'matricule' && student.matricule
      ? `N°ISGI : ${student.matricule}`
      : student[name] || '';
    if (name === 'matricule') {
      ctx.fillStyle = '#FF6B00';
      ctx.fillRect(330, 1364, 420, 90);
    }
    ctx.font = `${field.bold ? 'bold ' : ''}${field.fontSize}px "${field.fontFamily}", Arial, sans-serif`;
    ctx.fillStyle = field.color;
    ctx.textAlign = field.align;
    let text = value;
    while (text && ctx.measureText(text).width > field.maxWidth && text.length > 3) {
      text = `${text.slice(0, -2)}…`;
    }
    if (text) ctx.fillText(text, field.x, field.y, field.maxWidth);
    textBounds[name] = getBadgeTextBounds(ctx, field, text);
  }
  ctx.textAlign = 'start';

  // 4. Draw QR Code in white square (x: 770, y: 1295, w: 195, h: 195)
  const qrContent = buildBadgeQrPayload(student);
  if (qrContent) {
    await drawQRCodeOnCanvas(ctx, qrContent, 772, 1297, 191, 191);
  }
  if (showSelection) drawBadgeSelection(ctx, textBounds);
}

function buildBadgeQrPayload(student) {
  const matricule = (student?.matricule || '').trim();
  const nom = (student?.nom || '').trim();
  const prenom = (student?.prenom || '').trim();
  if (!matricule && !nom && !prenom) {
    return 'ISGI-BADGE-ETUDIANT';
  }

  return JSON.stringify({
    type: 'ISGI_STUDENT',
    version: 1,
    matricule: matricule.toUpperCase(),
    nom: nom.toUpperCase(),
    prenom
  });
}

function drawBadgeSelection(ctx, textBounds) {
  ctx.save();
  ctx.strokeStyle = '#22d3ee';
  ctx.lineWidth = 3;
  ctx.setLineDash([10, 7]);
  if (selectedBadgeElement === 'photo') {
    const frame = badgePhotoFrame;
    ctx.strokeRect(frame.x - 3, frame.y - 3, frame.width + 6, frame.height + 6);
  } else {
    const bounds = textBounds[selectedBadgeElement];
    if (bounds) ctx.strokeRect(bounds.left - 8, bounds.top - 5, bounds.width + 16, bounds.height + 10);
  }
  ctx.restore();
}

function drawRoundedPhoto(ctx, photoObj, frame, transform, radius) {
  return new Promise((resolve) => {
    const img = new Image();
    const src = photoObj.base64.startsWith('data:') || photoObj.base64.startsWith('http')
      ? photoObj.base64
      : `data:image/${photoObj.ext || 'jpeg'};base64,${photoObj.base64}`;
    if (!src.startsWith('data:')) {
      img.crossOrigin = 'anonymous';
    }
    img.onload = () => {
      try {
        ctx.save();
        ctx.beginPath();
        if (ctx.roundRect) {
          ctx.roundRect(frame.x, frame.y, frame.width, frame.height, radius);
        } else {
          ctx.moveTo(frame.x + radius, frame.y);
          ctx.arcTo(frame.x + frame.width, frame.y, frame.x + frame.width, frame.y + frame.height, radius);
          ctx.arcTo(frame.x + frame.width, frame.y + frame.height, frame.x, frame.y + frame.height, radius);
          ctx.arcTo(frame.x, frame.y + frame.height, frame.x, frame.y, radius);
          ctx.arcTo(frame.x, frame.y, frame.x + frame.width, frame.y, radius);
          ctx.closePath();
        }
        ctx.clip();

        const coverScale = Math.max(frame.width / img.width, frame.height / img.height);
        const drawW = img.width * coverScale * transform.zoom;
        const drawH = img.height * coverScale * transform.zoom;
        const maxOffsetX = Math.max(0, (drawW - frame.width) / 2);
        const maxOffsetY = Math.max(0, (drawH - frame.height) / 2);
        const offsetX = Math.max(-maxOffsetX, Math.min(maxOffsetX, transform.offsetX));
        const offsetY = Math.max(-maxOffsetY, Math.min(maxOffsetY, transform.offsetY));
        const drawX = frame.x + (frame.width - drawW) / 2 + offsetX;
        const drawY = frame.y + (frame.height - drawH) / 2 + offsetY;
        ctx.drawImage(img, drawX, drawY, drawW, drawH);
        ctx.restore();
      } catch (err) {
        console.warn('Erreur rendu photo sur canvas:', err);
      }
      resolve();
    };
    img.onerror = resolve;
    img.src = src;
  });
}

async function drawQRCodeOnCanvas(ctx, text, x, y, w, h) {
  if (!text) return;

  // 1. Rendu vectoriel direct ultra-rapide (zéro canvas temporaire, zéro taint, 100% net)
  const qrLib = (typeof QRCode !== 'undefined' && QRCode) || window.QRCode;
  if (qrLib && typeof qrLib.create === 'function') {
    try {
      const qrData = qrLib.create(text, { errorCorrectionLevel: 'M' });
      const moduleCount = qrData.modules.size;
      const margin = 1;
      const totalCount = moduleCount + margin * 2;
      const cellSize = w / totalCount;

      ctx.save();
      // Fond blanc du QR
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(x, y, w, h);

      // Modules bleu foncé ISGI (#003087)
      ctx.fillStyle = '#003087';
      for (let r = 0; r < moduleCount; r++) {
        for (let c = 0; c < moduleCount; c++) {
          if (qrData.modules.get(r, c)) {
            const px = Math.round(x + (c + margin) * cellSize);
            const py = Math.round(y + (r + margin) * cellSize);
            const pw = Math.ceil(cellSize);
            const ph = Math.ceil(cellSize);
            ctx.fillRect(px, py, pw, ph);
          }
        }
      }
      ctx.restore();
      return;
    } catch (err) {
      console.warn('Génération QR vectorielle directe échouée:', err);
    }
  }

  // 2. Repli avec l'API Electron native si disponible
  if (window.electronAPI && window.electronAPI.generateQR) {
    try {
      const dataUrl = await window.electronAPI.generateQR(text, {
        width: w,
        margin: 1,
        color: { dark: '#003087', light: '#ffffff' }
      });
      if (dataUrl) {
        await new Promise((resolve) => {
          const img = new Image();
          img.onload = () => {
            ctx.drawImage(img, x, y, w, h);
            resolve();
          };
          img.onerror = resolve;
          img.src = dataUrl;
        });
        return;
      }
    } catch (e) {
      console.warn('Génération QR native échouée, essai avec QRCode navigateur:', e);
    }
  }

  // 3. Repli toCanvas
  if (qrLib && typeof qrLib.toCanvas === 'function') {
    try {
      await new Promise((resolve) => {
        const tempCanvas = document.createElement('canvas');
        qrLib.toCanvas(tempCanvas, text, {
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
    } catch (e) {
      console.warn('Génération QR toCanvas échouée:', e);
    }
  }
}

// ── Export PNG HD (300 DPI) ────────────────────────────
async function exportBadgePNG() {
  const nom = (document.getElementById('badge-nom')?.value || 'ETUDIANT').trim();
  const prenom = (document.getElementById('badge-prenom')?.value || '').trim();
  const canvas = document.createElement('canvas');
  canvas.width = 1063;
  canvas.height = 1535;
  await renderOfficialBadgeCanvas(canvas, false);
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
async function printBadge() {
  const printWindow = window.open('', '_blank');
  if (!printWindow) {
    toast('Autorisez les fenêtres contextuelles pour imprimer le badge.', 'error');
    return;
  }
  const canvas = document.createElement('canvas');
  canvas.width = 1063;
  canvas.height = 1535;
  await renderOfficialBadgeCanvas(canvas, false);
  if (!canvas) return;
  const rectoData = canvas.toDataURL('image/png');
  const versoImg = document.getElementById('badgeOfficialVersoImg');
  const versoData = versoImg ? versoImg.src : './images/badge_verso.png';

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

async function getVersoArrayBuffer() {
  if (templateVersoImg.src && templateVersoImg.src.startsWith('data:image/')) {
    const b64 = templateVersoImg.src.split(',')[1];
    const binary = atob(b64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes.buffer;
  }
  const versoResponse = await fetch('./images/badge_verso.png');
  return await versoResponse.arrayBuffer();
}

function uint8ArrayToBase64(bytes) {
  if (typeof Buffer !== 'undefined') {
    return Buffer.from(bytes).toString('base64');
  }
  let binary = '';
  const len = bytes.byteLength;
  const chunkSize = 8192;
  for (let i = 0; i < len; i += chunkSize) {
    const chunk = bytes.subarray(i, Math.min(i + chunkSize, len));
    binary += String.fromCharCode.apply(null, chunk);
  }
  return btoa(binary);
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
    const canvas = document.createElement('canvas');
    canvas.width = 1063;
    canvas.height = 1535;
    await renderOfficialBadgeCanvas(canvas, false);
    const rectoPngBase64 = canvas.toDataURL('image/png').replace(/^data:image\/png;base64,/, '');

    // Fetch Verso image bytes
    const versoBuffer = await getVersoArrayBuffer();

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
    const pdfBase64 = uint8ArrayToBase64(pdfBytes);

    if (window.electronAPI && window.electronAPI.savePDF) {
      const result = await window.electronAPI.savePDF({
        pdfBase64,
        studentName: `${nom}_${prenom}`
      });
      if (result && result.success) {
        await saveBadgeToDB({ nom, prenom, matricule, lieu, filiere, niveau, annee });
        toast(`Badge PDF sauvegardé : ${result.filePath}`, 'success');
      }
    } else {
      const blob = new Blob([pdfBytes], { type: 'application/pdf' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `Badge_ISGI_${nom}_${prenom}.pdf`;
      a.click();
      await saveBadgeToDB({ nom, prenom, matricule, lieu, filiere, niveau, annee });
      toast('Badge PDF téléchargé !', 'success');
    }
  } catch (err) {
    console.error('PDF error:', err);
    toast('Erreur lors de la création du PDF : ' + err.message, 'error');
  } finally {
    hideLoading();
  }
}

// ── Save badge history to Supabase ─────────────────────
async function saveBadgeToDB(student) {
  const badge = {
    ...student,
    dateGeneration: new Date().toISOString(),
  };
  const savedRows = await supabaseRequest('/rest/v1/informaticien_badges', {
    method: 'POST',
    headers: { Prefer: 'return=representation' },
    body: JSON.stringify([{
      etudiant_id: etudiantsDB.find(item => item.matricule === student.matricule)?.id || null,
      nom: badge.nom,
      prenom: badge.prenom,
      matricule: badge.matricule,
      lieu_naissance: badge.lieu,
      filiere: badge.filiere,
      niveau: badge.niveau,
      annee_academique: badge.annee,
      date_generation: badge.dateGeneration
    }])
  });
  const saved = savedRows?.[0];
  if (!saved?.id) throw new Error('Le badge n’a pas été confirmé par la base de données.');
  badge.id = saved.id;
  badgeDB.unshift(badge);
  updateDashboardStats();
  renderRecentBadges();
  renderBadgeTable();
}

async function saveBatchBadgesToDB(students, annee) {
  const dateGeneration = new Date().toISOString();
  const rows = await supabaseRequest('/rest/v1/informaticien_badges', {
    method: 'POST',
    headers: { Prefer: 'return=representation' },
    body: JSON.stringify(students.map(student => ({
      etudiant_id: student.id,
      nom: student.nom,
      prenom: student.prenom,
      matricule: student.matricule,
      lieu_naissance: student.lieu || null,
      filiere: student.filiere || null,
      niveau: student.niveau || null,
      annee_academique: annee,
      date_generation: dateGeneration
    })))
  });
  if (!Array.isArray(rows) || rows.length !== students.length) {
    throw new Error('La base n’a pas confirmé l’enregistrement de tous les badges générés.');
  }
  badgeDB.unshift(...rows.map(row => ({
    id: row.id,
    studentId: row.etudiant_id,
    nom: row.nom,
    prenom: row.prenom,
    matricule: row.matricule,
    lieu: row.lieu_naissance || '',
    filiere: row.filiere || '',
    niveau: row.niveau || '',
    annee: row.annee_academique || '',
    dateGeneration: row.date_generation
  })));
  updateDashboardStats();
  renderRecentBadges();
  renderBadgeTable();
}

async function saveBadge() {
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

  try {
    await saveBadgeToDB({ nom, prenom, matricule, lieu, filiere, niveau, annee });
    toast('Badge enregistré dans Supabase.', 'success');
  } catch (error) {
    toast(`Impossible d’enregistrer le badge : ${error.message}`, 'error');
  }
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
    document.getElementById('badge-annee').value = badge.annee || '';
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

async function deleteBadge(id) {
  if (!confirm('Supprimer ce badge de la base ?')) return;
  try {
    await supabaseRequest(`/rest/v1/informaticien_badges?id=eq.${encodeURIComponent(id)}`, { method: 'DELETE' });
    badgeDB = badgeDB.filter(b => b.id !== id);
    renderBadgeTable();
    updateDashboardStats();
    renderRecentBadges();
  } catch (error) {
    toast(`Impossible de supprimer le badge : ${error.message}`, 'error');
    return;
  }
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

async function generateBatchPDF() {
  if (!batchStudents.length) return;

  const annee = document.getElementById('batch-annee').value;
  if (!annee) {
    toast('Sélectionnez une année académique issue de la base.', 'error');
    return;
  }
  const filiere = document.getElementById('batch-filiere').value || 'Toutes';
  const progressOverlay = document.getElementById('batchProgress');
  const progressBar = document.getElementById('batchProgressBar');
  const progressText = document.getElementById('batchProgressText');

  progressOverlay.classList.remove('hidden');

  try {
    const { PDFDocument, rgb } = PDFLib;
    const masterPdf = await PDFDocument.create();

    // Fetch Verso image bytes
    const versoBuffer = await getVersoArrayBuffer();
    const versoImage = await masterPdf.embedPng(versoBuffer);

    // Create offscreen canvas for rendering official recto
    const offCanvas = document.createElement('canvas');
    offCanvas.width = 1063;
    offCanvas.height = 1535;

    for (let i = 0; i < batchStudents.length; i++) {
      const student = { ...batchStudents[i], annee, directeur: appConfig.directeur || '' };

      // Update progress
      const pct = Math.round(((i + 1) / batchStudents.length) * 100);
      progressBar.style.width = `${pct}%`;
      progressText.textContent = `${i + 1} / ${batchStudents.length}`;

      // Render official recto canvas for this student
      const photoObj = student.photo ? { base64: student.photo, ext: 'jpeg' } : null;
      await renderBadgeToCanvas(offCanvas, student, photoObj, {
        photoTransform: { zoom: 1, offsetX: 0, offsetY: 0 }
      });
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
    const pdfBase64 = uint8ArrayToBase64(pdfBytes);

    progressOverlay.classList.add('hidden');

    if (window.electronAPI) {
      const result = await window.electronAPI.savePDFBatch({
        pdfBase64,
        batchName: `${filiere.replace(/\s+/g,'_')}_${annee.replace(' - ','_')}`
      });
      if (result.success) {
        await saveBatchBadgesToDB(batchStudents, annee);
        toast(`${batchStudents.length} badges générés et enregistrés dans Supabase → ${result.filePath}`, 'success');
      }
    } else {
      const blob = new Blob([pdfBytes], { type: 'application/pdf' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `Badges_Lot_${filiere.replace(/\s+/g,'_')}.pdf`;
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 60000);
      await saveBatchBadgesToDB(batchStudents, annee);
      toast(`${batchStudents.length} badges générés et enregistrés dans Supabase.`, 'success');
    }
  } catch (e) {
    console.error(e);
    progressOverlay.classList.add('hidden');
    toast('Erreur lors de la génération en lot', 'error');
  }
}

// ══ VIDÉOS (publique / classe) ════════════════════════

let currentVideoTab = 'publique';
let currentVideoFilter = 'all';
let currentVisibilite = 'publique';
let selectedClasseObj = null;

function initClassDropdowns() {
  const modalSel = document.getElementById('video-classe-select');
  const filterSel = document.getElementById('filter-classe');

  fillClassOptions(modalSel, '-- Choisir une classe --');
  fillClassOptions(filterSel, '🎓 Toutes les classes');
}

function fillClassOptions(select, placeholder) {
  if (!select) return;
  const previous = select.value;
  select.replaceChildren(new Option(placeholder, ''));
  academicClasses.forEach(item => {
    const option = new Option(item.nom, item.id);
    option.dataset.filiere = item.filiere;
    option.dataset.niveau = item.niveau;
    select.add(option);
  });
  if (academicClasses.some(item => item.id === previous)) select.value = previous;
}

function onVideoClasseSelectChange(val) {
  const found = academicClasses.find(c => c.id === val);
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

async function addVideo() {
  const titre = document.getElementById('video-titre').value.trim();
  const desc = document.getElementById('video-desc').value.trim();
  const categorie = document.getElementById('video-categorie').value;
  if (!titre) { toast('Le titre est requis', 'error'); return; }
  if (!currentVideoPath) { toast('Sélectionnez un fichier vidéo.', 'error'); return; }

  let classe = null;
  if (currentVisibilite === 'classe') {
    classe = academicClasses.find(item => item.id === document.getElementById('video-classe-select')?.value);
    if (!classe) { toast('Sélectionnez une classe existante dans la base académique.', 'error'); return; }
  }

  const session = JSON.parse(localStorage.getItem(authSessionKey) || '{}');
  const fileName = currentVideoPath.split('\\').pop();
  const extension = fileName.split('.').pop().toLowerCase();
  const videoMimeTypes = {
    mp4: 'video/mp4', webm: 'video/webm', mkv: 'video/x-matroska',
    avi: 'video/avi', mov: 'video/quicktime'
  };
  const contentType = videoMimeTypes[extension];
  if (!contentType) { toast('Format vidéo non pris en charge.', 'error'); return; }

  const basePath = `${session.userId}/${Date.now()}-${fileName.replace(/[^a-zA-Z0-9._-]/g, '_')}`;
  showLoading('Téléversement et publication de la vidéo...');
  let videoPath = null;
  let thumbnailPath = null;
  let videoPublished = false;
  try {
    const uploadedVideo = await window.electronAPI.uploadVideo({
      filePath: currentVideoPath,
      objectPath: basePath,
      userId: session.userId,
      accessToken: await getCurrentAccessToken(),
      contentType
    });
    videoPath = uploadedVideo.storagePath;

    if (currentThumbBase64) {
      const thumbnail = await (await fetch(currentThumbBase64)).blob();
      const thumbExtension = thumbnail.type === 'image/png' ? 'png' : thumbnail.type === 'image/webp' ? 'webp' : 'jpg';
      thumbnailPath = `${session.userId}/${Date.now()}-thumb.${thumbExtension}`;
      const encodedPath = thumbnailPath.split('/').map(encodeURIComponent).join('/');
      await supabaseRequest(`/storage/v1/object/videos-isgi/${encodedPath}`, {
        method: 'POST',
        headers: { 'Content-Type': thumbnail.type, 'x-upsert': 'false' },
        body: thumbnail
      });
    }

    const responseRows = await supabaseRequest('/rest/v1/videos', {
      method: 'POST',
      headers: { Prefer: 'return=representation' },
      body: JSON.stringify([{
        titre,
        description: desc,
        categorie,
        visibilite: currentVisibilite,
        classe_id: classe?.id || null,
        classe_nom: classe?.nom || null,
        classe_filiere: classe?.filiere || null,
        classe_niveau: classe?.niveau || null,
        url_video: videoPath,
        url_miniature: thumbnailPath,
        publie_par: session.userId,
        publie_par_nom: document.getElementById('signedInUser')?.textContent || 'Service Informatique',
        statut: 'publie'
      }])
    });
    if (!responseRows?.[0]?.id) throw new Error('Supabase n’a pas confirmé la publication de la vidéo.');
    videoPublished = true;

    closeAddVideoModal();
    await loadSupabaseData();
    const tabBtn = document.querySelector('[data-tab="' + currentVisibilite + '"]');
    if (tabBtn) switchVideoTab(currentVisibilite, tabBtn);
    const where = currentVisibilite === 'publique' ? 'bibliothèque publique' : `classe ${classe.nom}`;
    toast(`Vidéo « ${titre} » publiée dans la ${where}.`, 'success');
  } catch (error) {
    console.error('Publication vidéo impossible:', error);
    if (!videoPublished) {
      for (const objectPath of [thumbnailPath, videoPath]) {
        if (!objectPath) continue;
        try {
          await deleteStorageObjects('videos-isgi', [objectPath]);
        } catch (cleanupError) {
          console.error(`Nettoyage du stockage impossible (${objectPath}):`, cleanupError);
        }
      }
    }
    toast(videoPublished
      ? `Vidéo publiée, mais l’actualisation des données a échoué : ${error.message}`
      : `Impossible de publier la vidéo : ${error.message}`, 'error');
  } finally {
    hideLoading();
  }
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

async function deleteVideo(id) {
  if (!confirm('Supprimer cette vidéo de la bibliothèque ?')) return;
  const video = videoDB.find(item => item.id === id);
  if (!video) return;
  try {
    await supabaseRequest(`/rest/v1/videos?id=eq.${encodeURIComponent(id)}`, { method: 'DELETE' });
    videoDB = videoDB.filter(item => item.id !== id);
    renderVideoGrid();
    updateDashboardStats();
    let cleanupFailed = false;
    for (const objectPath of [video.storagePath, video.thumbnailPath]) {
      if (!objectPath) continue;
      try {
        await deleteStorageObjects('videos-isgi', [objectPath]);
      } catch (storageError) {
        cleanupFailed = true;
        console.error(`Suppression du fichier vidéo impossible (${objectPath}):`, storageError);
      }
    }
    toast(cleanupFailed
      ? 'Vidéo supprimée de la base; un fichier de stockage n’a pas pu être supprimé.'
      : 'Vidéo supprimée de Supabase.', cleanupFailed ? 'error' : 'info');
  } catch (error) {
    toast(`Impossible de supprimer la vidéo : ${error.message}`, 'error');
  }
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

  if (video.storagePath) {
    try {
      player.src = await getSignedStorageUrl('videos-isgi', video.storagePath);
    } catch (error) {
      toast(`Impossible de lire la vidéo : ${error.message}`, 'error');
      return;
    }
  } else if (video.path) {
    player.src = video.path;
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
    await loadSupabaseData();
    toast(`${etudiantsDB.length} étudiant(s) synchronisés`, 'success');
  } catch (e) {
    toast(`Erreur de synchronisation Supabase : ${e.message}`, 'error');
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
    tbody.innerHTML = '<tr><td colspan="7" class="empty-cell">Aucun étudiant présent dans la base de données.</td></tr>';
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

async function saveAppParams() {
  const directeur = document.getElementById('param-directeur').value.trim();
  const anneeDefaut = document.getElementById('param-annee').value;
  try {
    await supabaseRequest('/rest/v1/informaticien_parametres?on_conflict=id', {
      method: 'POST',
      headers: { Prefer: 'resolution=merge-duplicates,return=minimal' },
      body: JSON.stringify([{ id: 'global', directeur, annee_defaut: anneeDefaut || null }])
    });
    appConfig.directeur = directeur;
    appConfig.anneeDefaut = anneeDefaut;
    const localConfig = JSON.parse(localStorage.getItem('isgi_config') || '{}');
    delete localConfig.directeur;
    delete localConfig.anneeDefaut;
    localConfig.outputDir = appConfig.outputDir;
    localStorage.setItem('isgi_config', JSON.stringify(localConfig));
    toast('Paramètres enregistrés dans Supabase.', 'success');
  } catch (error) {
    toast(`Impossible d'enregistrer les paramètres : ${error.message}`, 'error');
  }
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

function checkSupabaseConnection() {
  const dot = document.getElementById('statusDot');
  const text = document.getElementById('statusText');
  dot.className = 'status-dot';
  text.textContent = 'Connexion requise';
}

function setSupabaseStatus(connected, label) {
  const dot = document.getElementById('statusDot');
  const text = document.getElementById('statusText');
  dot.className = `status-dot ${connected ? 'online' : 'offline'}`;
  text.textContent = label;
}

// ══ AUTHENTICATION ════════════════════════════════════

const authSessionKey = 'isgi_auth_session';
const allowedAuthRoles = ['informaticien', 'informaticiens', 'admin', 'admin_principal'];

function setLoginStatus(message) {
  const status = document.getElementById('loginStatus');
  status.textContent = message;
  status.classList.toggle('hidden', !message);
}

async function readSupabaseResponse(response) {
  let body;
  try {
    body = await response.json();
  } catch {
    throw new Error(`Réponse Supabase illisible (HTTP ${response.status}).`);
  }

  if (!response.ok) {
    const message = body.msg || body.message || body.error_description || body.error;
    const error = new Error(message || `Erreur Supabase (HTTP ${response.status}).`);
    error.status = response.status;
    error.code = body.code;
    throw error;
  }

  return body;
}

async function getCurrentAccessToken() {
  const stored = localStorage.getItem(authSessionKey);
  if (!stored) throw new Error('Connectez-vous pour accéder aux données ISGI.');

  const session = JSON.parse(stored);
  if (session.expiresAt < Date.now() + 60_000) {
    const response = await fetch(`${appConfig.supabaseUrl}/auth/v1/token?grant_type=refresh_token`, {
      method: 'POST',
      headers: {
        apikey: appConfig.supabaseKey,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ refresh_token: session.refreshToken })
    });
    const refreshed = await readSupabaseResponse(response);
    session.accessToken = refreshed.access_token;
    session.refreshToken = refreshed.refresh_token;
    session.expiresAt = Date.now() + (refreshed.expires_in || 3600) * 1000;
    localStorage.setItem(authSessionKey, JSON.stringify(session));
  }

  return session.accessToken;
}

async function supabaseRequest(path, options = {}) {
  const accessToken = await getCurrentAccessToken();
  const response = await fetch(`${appConfig.supabaseUrl}${path}`, {
    ...options,
    headers: {
      apikey: appConfig.supabaseKey,
      Authorization: `Bearer ${accessToken}`,
      ...(options.body && !(options.body instanceof Blob) ? { 'Content-Type': 'application/json' } : {}),
      ...options.headers
    }
  });

  if (response.status === 204) return null;
  return readSupabaseResponse(response);
}

async function fetchSupabaseRows(table, query = 'select=*') {
  const rows = [];
  let offset = 0;

  while (true) {
    const response = await fetch(`${appConfig.supabaseUrl}/rest/v1/${table}?${query}`, {
      headers: {
        apikey: appConfig.supabaseKey,
        Authorization: `Bearer ${await getCurrentAccessToken()}`,
        Range: `${offset}-${offset + 999}`
      }
    });
    const page = await readSupabaseResponse(response);
    if (!Array.isArray(page)) throw new Error(`Réponse inattendue pour la table ${table}.`);
    rows.push(...page);
    if (page.length < 1000) return rows;
    offset += page.length;
  }
}

async function fetchOptionalSupabaseRows(table, query) {
  try {
    return await fetchSupabaseRows(table, query);
  } catch (error) {
    const tableMissing = error.status === 404 &&
      (error.code === 'PGRST205' || error.message.includes(`public.${table}`));
    if (!tableMissing) throw error;
    console.warn(`Table public.${table} absente; exécutez la migration SQL de l'application Informaticien.`);
    return null;
  }
}

async function ensureStorageBucket(bucketName) {
  try {
    const res = await fetch(`${appConfig.supabaseUrl}/storage/v1/bucket/${bucketName}`, {
      headers: {
        apikey: appConfig.supabaseKey,
        Authorization: `Bearer ${await getCurrentAccessToken()}`
      }
    });
    if (res.status === 404) {
      await supabaseRequest('/storage/v1/bucket', {
        method: 'POST',
        body: JSON.stringify({ id: bucketName, name: bucketName, public: true, fileSizeLimit: 5242880 })
      });
      console.log(`Bucket "${bucketName}" créé en accès public dans Supabase Storage.`);
    }
  } catch (e) {
    console.warn(`Vérification/création bucket "${bucketName}" échouée:`, e);
  }
}

function normalizeStorageUrl(url) {
  if (!url) return null;
  return url.replace(/(\.supabase\.co)\/object\//, '$1/storage/v1/object/');
}

async function getSignedStorageUrl(bucket, objectPath) {
  if (!objectPath) return null;
  if (/^https?:\/\//i.test(objectPath)) return normalizeStorageUrl(objectPath);

  const result = await supabaseRequest(`/storage/v1/object/sign/${bucket}/${objectPath.split('/').map(encodeURIComponent).join('/')}`, {
    method: 'POST',
    body: JSON.stringify({ expiresIn: 86400 }) // 24h
  });
  const signedPath = result.signedURL || result.signedUrl;
  if (!signedPath) throw new Error(`Supabase n’a pas renvoyé d’URL de lecture pour ${objectPath}.`);
  if (signedPath.startsWith('http')) return normalizeStorageUrl(signedPath);
  const normalizedPath = signedPath.startsWith('/storage/v1')
    ? signedPath
    : `/storage/v1${signedPath.startsWith('/') ? '' : '/'}${signedPath}`;
  return `${appConfig.supabaseUrl}${normalizedPath}`;
}

function blobToDataUrl(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = () => reject(reader.error || new Error('Erreur de conversion de l’image'));
    reader.readAsDataURL(blob);
  });
}

async function getSignedStorageDataUrl(bucket, objectPath) {
  if (!objectPath) return null;
  if (objectPath.startsWith('data:')) return objectPath;

  try {
    const signedUrl = await getSignedStorageUrl(bucket, objectPath);
    if (!signedUrl) return null;
    const response = await fetch(signedUrl);
    if (!response.ok) {
      console.warn(`Téléchargement de la photo (${objectPath}) : HTTP ${response.status}`);
      return null;
    }
    const blob = await response.blob();
    return await blobToDataUrl(blob);
  } catch (error) {
    console.warn(`Impossible de télécharger la photo (${objectPath}):`, error);
    return null;
  }
}

async function deleteStorageObjects(bucket, objectPaths) {
  if (!objectPaths.length) return;
  await supabaseRequest(`/storage/v1/object/${bucket}`, {
    method: 'DELETE',
    body: JSON.stringify({ prefixes: objectPaths })
  });
}

function fillOptions(selectId, values, placeholder) {
  const select = document.getElementById(selectId);
  if (!select) return;
  const previous = select.value;
  select.replaceChildren(new Option(placeholder, ''));
  [...new Set(values.map(value => String(value || '').trim()).filter(Boolean))]
    .sort((a, b) => a.localeCompare(b, 'fr'))
    .forEach(value => {
    select.add(new Option(value, value));
  });
  if (values.includes(previous)) select.value = previous;
}

function populateAcademicOptions() {
  const filieres = [
    ...academicClasses.map(item => item.filiere),
    ...etudiantsDB.map(item => item.filiere)
  ];
  const niveaux = [
    ...academicClasses.map(item => item.niveau),
    ...etudiantsDB.map(item => item.niveau)
  ];
  const annees = [
    ...academicClasses.map(item => item.annee_academique),
    ...etudiantsDB.map(item => item.annee_academique),
    appConfig.anneeDefaut
  ];

  fillOptions('badge-filiere', filieres, '-- Choisir --');
  fillOptions('badge-niveau', niveaux, '-- Choisir --');
  fillOptions('badge-annee', annees, '-- Choisir --');
  fillOptions('batch-filiere', filieres, 'Toutes les filières');
  fillOptions('batch-niveau', niveaux, 'Tous les niveaux');
  fillOptions('batch-annee', annees, '-- Choisir une année --');
  fillOptions('param-annee', annees, '-- Choisir une année --');
  document.getElementById('param-annee').value = appConfig.anneeDefaut || '';
  document.getElementById('badge-annee').value ||= appConfig.anneeDefaut || '';
  document.getElementById('batch-annee').value ||= appConfig.anneeDefaut || '';
}

async function loadSupabaseData() {
  showLoading('Chargement des données académiques...');
  // S'assurer que le bucket de photos existe dans Supabase Storage
  await ensureStorageBucket('student-photos');
  try {
    const [students, classes, badgesResult, videos, parametersResult] = await Promise.all([
      fetchSupabaseRows('etudiants', 'select=*&order=nom.asc'),
      fetchSupabaseRows('classes', 'select=*&order=nom.asc'),
      fetchOptionalSupabaseRows('informaticien_badges', 'select=*&order=date_generation.desc'),
      fetchSupabaseRows('videos', 'select=*&order=created_at.desc'),
      fetchOptionalSupabaseRows('informaticien_parametres', 'select=*&id=eq.global')
    ]);
    const badges = badgesResult || [];
    const parameters = parametersResult || [];
    const missingTables = [
      ...(badgesResult === null ? ['informaticien_badges'] : []),
      ...(parametersResult === null ? ['informaticien_parametres'] : [])
    ];
    const setupNotice = document.getElementById('supabaseSetupNotice');
    if (missingTables.length) {
      setupNotice.textContent = `Migration Supabase requise : table(s) manquante(s) ${missingTables.map(name => `public.${name}`).join(', ')}. Exécutez app_informaticien/supabase_photo_setup.sql dans le SQL Editor Supabase, puis actualisez les données.`;
      setupNotice.classList.remove('hidden');
      toast('La connexion fonctionne, mais les tables de l’application manquent. Consultez Paramètres pour appliquer la migration Supabase.', 'error');
    } else {
      setupNotice.textContent = '';
      setupNotice.classList.add('hidden');
    }
    appConfig.anneeDefaut = parameters[0]?.annee_defaut || '';
    appConfig.directeur = parameters[0]?.directeur || '';
    document.getElementById('param-directeur').value = appConfig.directeur;

    const classesById = new Map(classes.map(item => [String(item.id), item]));
    const photoLoadFailures = [];
    const cachedPhotos = getLocalStudentPhotos();
    etudiantsDB = await Promise.all(students.map(async student => {
      const photoPath = student.photo_url && !/^https?:\/\//i.test(student.photo_url) && student.photo_url !== 'local' ? student.photo_url : null;
      let photo = null;
      if (photoPath) {
        try {
          photo = await getSignedStorageDataUrl('student-photos', photoPath);
        } catch (error) {
          console.warn(`Chargement de la photo Supabase impossible pour ${student.matricule || student.id}:`, error);
        }
      }
      // Restaurer la photo depuis le cache local si indisponible sur Supabase
      if (!photo) {
        if (student.matricule && cachedPhotos[student.matricule]) {
          photo = cachedPhotos[student.matricule];
        } else if (student.id && cachedPhotos[String(student.id)]) {
          photo = cachedPhotos[String(student.id)];
        }
      } else {
        // Mettre en cache local pour affichage immédiat au prochain redémarrage
        saveLocalStudentPhoto(student, photo);
      }
      return {
        ...student,
        id: String(student.id),
        nom: student.nom || student.last_name || '',
        prenom: student.prenom || student.first_name || '',
        matricule: student.matricule || '',
        filiere: student.filiere || classesById.get(String(student.classe_id))?.filiere || '',
        niveau: student.niveau || classesById.get(String(student.classe_id))?.niveau || '',
        lieu: student.lieu_naissance || '',
        annee_academique: student.annee_academique || '',
        photoPath,
        photo
      };
    }));
    if (photoLoadFailures.length) {
      toast(`Impossible de charger ${photoLoadFailures.length} photo(s) étudiant. Les fiches et les badges restent disponibles; vérifiez le stockage Supabase.`, 'error');
    }
    academicClasses = classes.map(item => ({
      id: String(item.id),
      nom: item.nom || item.code || String(item.id),
      filiere: item.filiere || '',
      niveau: item.niveau || '',
      annee_academique: item.annee_academique || ''
    }));
    badgeDB = badges.map(item => ({
      id: item.id,
      studentId: item.etudiant_id,
      nom: item.nom,
      prenom: item.prenom,
      matricule: item.matricule,
      lieu: item.lieu_naissance || '',
      filiere: item.filiere || '',
      niveau: item.niveau || '',
      annee: item.annee_academique || '',
      dateGeneration: item.date_generation
    }));
    videoDB = await Promise.all(videos.map(async item => ({
      id: item.id,
      titre: item.titre,
      desc: item.description || '',
      categorie: item.categorie,
      visibilite: item.visibilite,
      classe_id: item.classe_id,
      classe_nom: item.classe_nom,
      classe_filiere: item.classe_filiere,
      classe_niveau: item.classe_niveau,
      classe_label: item.visibilite === 'classe' ? `🎓 ${item.classe_nom || item.classe_id}` : '🌐 Publique',
      storagePath: item.url_video || '',
      thumbnailPath: item.url_miniature || '',
      thumb: item.url_miniature ? await getSignedStorageUrl('videos-isgi', item.url_miniature) : '',
      path: item.url_video ? await getSignedStorageUrl('videos-isgi', item.url_video) : '',
      dateAjout: item.created_at,
      publie_par_nom: item.publie_par_nom || '',
      vues: item.vues || 0
    })));

    populateAcademicOptions();
    renderEtudiantsTable();
    renderBadgeStudioStudents();
    renderBadgeTable();
    renderRecentBadges();
    renderVideoGrid();
    updateDashboardStats();
    loadBatchStudents();
    setSupabaseStatus(true, missingTables.length ? 'Migration requise' : 'Connecté');
  } catch (error) {
    setSupabaseStatus(false, error.status === 401 || error.status === 403 ? 'Accès refusé' : 'Erreur Supabase');
    toast(`Impossible de charger les données Supabase : ${error.message}`, 'error');
    throw error;
  } finally {
    hideLoading();
  }
}

async function getAuthorizedProfile(userId, accessToken) {
  const query = new URLSearchParams({
    id: `eq.${userId}`,
    select: 'id,nom_complet,role,statut'
  });
  const response = await fetch(`${appConfig.supabaseUrl}/rest/v1/utilisateurs?${query}`, {
    headers: {
      apikey: appConfig.supabaseKey,
      Authorization: `Bearer ${accessToken}`
    }
  });
  const profiles = await readSupabaseResponse(response);
  const profile = profiles[0];

  if (!profile) {
    throw new Error('Aucun profil Informaticien associé à ce compte. Contacte l’administrateur.');
  }
  if (profile.statut !== 'actif' || !allowedAuthRoles.includes(profile.role)) {
    throw new Error('Accès refusé : ce compte n’est pas autorisé dans l’application Informaticien.');
  }

  return profile;
}

function showAuthenticatedUser(profile, email, accessToken, refreshToken, expiresAt) {
  localStorage.setItem(authSessionKey, JSON.stringify({
    userId: profile.id,
    name: profile.nom_complet || email,
    email,
    accessToken,
    refreshToken,
    expiresAt
  }));
  document.getElementById('signedInUser').textContent = profile.nom_complet || email;
  document.getElementById('signedInUser').classList.remove('hidden');
  document.getElementById('signOutButton').classList.remove('hidden');
  document.getElementById('authScreen').classList.add('hidden');
}

function showLoginScreen(message = '') {
  document.getElementById('signedInUser').classList.add('hidden');
  document.getElementById('signOutButton').classList.add('hidden');
  document.getElementById('authScreen').classList.remove('hidden');
  if (message) setLoginStatus(message);
}

async function signIn(event) {
  event.preventDefault();
  const button = document.getElementById('authSubmit');
  const email = document.getElementById('auth-email').value.trim();
  const password = document.getElementById('auth-password').value;
  button.disabled = true;
  button.textContent = 'Connexion en cours...';
  setLoginStatus('');
  let authSession = null;

  try {
    const response = await fetch(`${appConfig.supabaseUrl}/auth/v1/token?grant_type=password`, {
      method: 'POST',
      headers: {
        apikey: appConfig.supabaseKey,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ email, password })
    });
    authSession = await readSupabaseResponse(response);
    const profile = await getAuthorizedProfile(authSession.user.id, authSession.access_token);
    const expiresAt = Date.now() + (authSession.expires_in || 3600) * 1000;
    showAuthenticatedUser(profile, email, authSession.access_token, authSession.refresh_token, expiresAt);
    setLoginStatus('');
    try {
      await loadSupabaseData();
    } catch (dataError) {
      console.error('Chargement des données Supabase impossible:', dataError);
    }
  } catch (error) {
    if (authSession?.access_token) {
      try {
        const signOutResponse = await fetch(`${appConfig.supabaseUrl}/auth/v1/logout`, {
          method: 'POST',
          headers: {
            apikey: appConfig.supabaseKey,
            Authorization: `Bearer ${authSession.access_token}`
          }
        });
        if (!signOutResponse.ok) {
          throw new Error(`HTTP ${signOutResponse.status}`);
        }
      } catch (signOutError) {
        console.error('Impossible de fermer la session du compte non autorisé:', signOutError);
      }
    }
    const message = error.message || 'Connexion impossible. Vérifie ta connexion Internet et réessaie.';
    if (/invalid login credentials/i.test(message)) {
      setLoginStatus('Adresse e-mail ou mot de passe incorrect.');
    } else if (/email not confirmed/i.test(message)) {
      setLoginStatus('Cette adresse e-mail n’a pas encore été confirmée.');
    } else {
      setLoginStatus(message);
    }
  } finally {
    button.disabled = false;
    button.textContent = 'Se connecter';
  }
}

async function restoreAuthSession() {
  const storedSession = localStorage.getItem(authSessionKey);
  if (!storedSession) return;

  let authenticated = false;
  try {
    const saved = JSON.parse(storedSession);
    let accessToken = saved.accessToken;
    let refreshToken = saved.refreshToken;
    let expiresAt = saved.expiresAt;

    if (expiresAt < Date.now() + 60_000) {
      const refreshResponse = await fetch(`${appConfig.supabaseUrl}/auth/v1/token?grant_type=refresh_token`, {
        method: 'POST',
        headers: {
          apikey: appConfig.supabaseKey,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ refresh_token: refreshToken })
      });
      const refreshed = await readSupabaseResponse(refreshResponse);
      accessToken = refreshed.access_token;
      refreshToken = refreshed.refresh_token;
      expiresAt = Date.now() + (refreshed.expires_in || 3600) * 1000;
    } else {
      const userResponse = await fetch(`${appConfig.supabaseUrl}/auth/v1/user`, {
        headers: {
          apikey: appConfig.supabaseKey,
          Authorization: `Bearer ${accessToken}`
        }
      });
      const user = await readSupabaseResponse(userResponse);
      if (user.id !== saved.userId) throw new Error('Session Supabase invalide.');
    }

    const profile = await getAuthorizedProfile(saved.userId, accessToken);
    showAuthenticatedUser(profile, saved.email, accessToken, refreshToken, expiresAt);
    authenticated = true;
    await loadSupabaseData();
  } catch (error) {
    if (authenticated) {
      console.error('Chargement/restauration des données Supabase impossible:', error);
      toast(`Données indisponibles : ${error.message}`, 'error');
      return;
    }
    localStorage.removeItem(authSessionKey);
    showLoginScreen(error.message || 'Ta session a expiré. Reconnecte-toi.');
  }
}

async function signOut() {
  const storedSession = localStorage.getItem(authSessionKey);
  localStorage.removeItem(authSessionKey);
  showLoginScreen();

  if (!storedSession) return;
  try {
    const saved = JSON.parse(storedSession);
    const response = await fetch(`${appConfig.supabaseUrl}/auth/v1/logout`, {
      method: 'POST',
      headers: {
        apikey: appConfig.supabaseKey,
        Authorization: `Bearer ${saved.accessToken}`
      }
    });
    if (!response.ok) throw new Error(`Déconnexion Supabase échouée (HTTP ${response.status}).`);
  } catch (error) {
    setLoginStatus(error.message || 'La session locale est fermée, mais la déconnexion Supabase a échoué.');
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

// ══ THÈME CLAIR / SOMBRE ════════════════════════════════
function initTheme() {
  const savedTheme = localStorage.getItem('isgi_theme') || localStorage.getItem('theme') || 'dark';
  applyTheme(savedTheme, false);
}

function applyTheme(theme, notify = false) {
  const currentTheme = theme === 'light' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', currentTheme);
  try {
    localStorage.setItem('isgi_theme', currentTheme);
    localStorage.setItem('theme', currentTheme);
    document.cookie = `isgi_theme=${currentTheme}; max-age=${30 * 24 * 60 * 60}; path=/`;
  } catch (e) {}

  if (window.electronAPI && typeof window.electronAPI.setTheme === 'function') {
    try { window.electronAPI.setTheme(currentTheme); } catch (e) {}
  }

  // Mettre à jour le bouton supérieur (Titlebar)
  const topBtn = document.getElementById('themeToggleTitlebarBtn');
  if (topBtn) {
    if (currentTheme === 'dark') {
      topBtn.innerHTML = `
        <svg class="theme-icon-sun" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="5"></circle>
          <line x1="12" y1="1" x2="12" y2="3"></line>
          <line x1="12" y1="21" x2="12" y2="23"></line>
          <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
          <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
          <line x1="1" y1="12" x2="3" y2="12"></line>
          <line x1="21" y1="12" x2="23" y2="12"></line>
          <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
          <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
        </svg>
        <span class="theme-btn-text">Mode Clair</span>
      `;
      topBtn.setAttribute('title', 'Passer en mode clair');
      topBtn.setAttribute('aria-label', 'Passer en mode clair');
    } else {
      topBtn.innerHTML = `
        <svg class="theme-icon-moon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
        </svg>
        <span class="theme-btn-text">Mode Sombre</span>
      `;
      topBtn.setAttribute('title', 'Passer en mode sombre');
      topBtn.setAttribute('aria-label', 'Passer en mode sombre');
    }
  }

  // Mettre à jour le bouton de la barre latérale (Sidebar)
  const sideBtn = document.getElementById('themeToggleSidebarBtn');
  if (sideBtn) {
    const isDark = currentTheme === 'dark';
    sideBtn.innerHTML = `
      <div class="theme-toggle-sidebar-left">
        <span class="theme-toggle-icon">
          ${isDark ? `
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="5"></circle>
              <line x1="12" y1="1" x2="12" y2="3"></line>
              <line x1="12" y1="21" x2="12" y2="23"></line>
              <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
              <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
              <line x1="1" y1="12" x2="3" y2="12"></line>
              <line x1="21" y1="12" x2="23" y2="12"></line>
              <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
              <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
            </svg>
          ` : `
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
            </svg>
          `}
        </span>
        <span class="theme-toggle-label">${isDark ? 'Mode Clair' : 'Mode Sombre'}</span>
      </div>
      <span class="theme-toggle-badge">${isDark ? 'Nuit' : 'Jour'}</span>
    `;
    sideBtn.setAttribute('title', isDark ? 'Passer en mode clair' : 'Passer en mode sombre');
  }

  // Mettre à jour les cartes d'options dans Paramètres
  document.querySelectorAll('.theme-card-option').forEach(card => {
    const themeVal = card.getAttribute('data-theme-value');
    if (themeVal === currentTheme) {
      card.classList.add('active');
    } else {
      card.classList.remove('active');
    }
  });

  if (notify) {
    toast(currentTheme === 'light' ? 'Mode clair activé' : 'Mode sombre activé', 'info');
  }
}

function toggleTheme() {
  const current = document.documentElement.getAttribute('data-theme') || 'dark';
  const target = current === 'dark' ? 'light' : 'dark';
  applyTheme(target, true);
}

window.toggleTheme = toggleTheme;
window.applyTheme = applyTheme;

// ═══════════════════════════════════════════════════════
//  GESTION DES MODÈLES DE BADGES PERSONNALISÉS
// ═══════════════════════════════════════════════════════

/**
 * Déclenche la sélection de fichier pour un côté donné ('recto' ou 'verso').
 */
function triggerTemplateUpload(side) {
  const inputId = side === 'recto' ? 'inputTemplateRecto' : 'inputTemplateVerso';
  const input = document.getElementById(inputId);
  if (input) input.click();
}

/**
 * Appelé quand l'utilisateur sélectionne un fichier image pour remplacer un template.
 * @param {Event} event - L'événement change du file input
 * @param {string} side - 'recto' ou 'verso'
 */
async function onTemplateFileInputChange(event, side) {
  const file = event.target.files?.[0];
  if (!file) return;

  // Vérification taille (max 10 Mo)
  if (file.size > 10 * 1024 * 1024) {
    toast('Image trop lourde (max 10 Mo). Veuillez choisir une image plus légère.', 'error');
    event.target.value = '';
    return;
  }

  // Lire l'image comme Data URL
  const dataUrl = await new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = e => resolve(e.target.result);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });

  // Mettre à jour l'aperçu dans les paramètres
  const previewId = side === 'recto' ? 'previewTemplateRecto' : 'previewTemplateVerso';
  const preview = document.getElementById(previewId);
  if (preview) preview.src = dataUrl;

  // Mettre à jour le statut visuel de la carte
  const boxId = side === 'recto' ? 'templateBoxRecto' : 'templateBoxVerso';
  const statusId = side === 'recto' ? 'statusBadgeRecto' : 'statusBadgeVerso';
  const btnResetId = side === 'recto' ? 'btnResetTemplateRecto' : 'btnResetTemplateVerso';
  const box = document.getElementById(boxId);
  const status = document.getElementById(statusId);
  const btnReset = document.getElementById(btnResetId);
  if (box) box.classList.add('has-custom');
  if (status) { status.textContent = 'Modèle Personnalisé'; status.className = 'template-status-badge status-custom'; }
  if (btnReset) btnReset.style.display = '';

  // Sauvegarder via Electron IPC (persistance dans userData)
  let saveOk = false;
  if (window.electronAPI?.saveCustomTemplate) {
    try {
      const base64Only = dataUrl.split(',')[1] || dataUrl;
      const result = await window.electronAPI.saveCustomTemplate({ side, dataBase64: base64Only });
      saveOk = result?.success;
    } catch (e) {
      console.error('Erreur sauvegarde template:', e);
    }
  }

  // Mettre à jour la mémoire et l'image de rendu en temps réel
  if (side === 'recto') {
    customTemplates.recto = dataUrl;
    templateRectoImg.src = dataUrl;
  } else {
    customTemplates.verso = dataUrl;
    templateVersoImg.src = dataUrl;
    const versoEl = document.getElementById('badgeOfficialVersoImg');
    if (versoEl) versoEl.src = dataUrl;
  }

  // Rafraîchir le canvas du badge studio si on est sur le côté correspondant
  if (side === 'recto' && activeBadgeSide === 'recto') {
    await renderOfficialBadgeCanvas();
  }

  if (saveOk) {
    toast(`✅ Modèle ${side === 'recto' ? 'Recto' : 'Verso'} mis à jour avec succès ! Il sera utilisé pour tous les prochains badges.`, 'success');
  } else {
    toast(`✅ Modèle ${side === 'recto' ? 'Recto' : 'Verso'} appliqué (session en cours). Relancez l'app pour une persistance complète.`, 'info');
  }

  // Réinitialiser l'input pour permettre de resélectionner le même fichier
  event.target.value = '';
}

/**
 * Réinitialise le modèle d'un côté au template officiel par défaut.
 * @param {string} side - 'recto' ou 'verso'
 */
async function resetBadgeTemplate(side) {
  const defaultSrc = side === 'recto' ? './images/badge_recto.png' : './images/badge_verso.png';

  // Supprimer la persistance via IPC
  if (window.electronAPI?.resetCustomTemplate) {
    try {
      await window.electronAPI.resetCustomTemplate({ side });
    } catch (e) {
      console.error('Erreur reset template:', e);
    }
  }

  // Remettre à jour l'image en mémoire
  if (side === 'recto') {
    customTemplates.recto = null;
    templateRectoImg.src = defaultSrc;
  } else {
    customTemplates.verso = null;
    templateVersoImg.src = defaultSrc;
    const versoEl = document.getElementById('badgeOfficialVersoImg');
    if (versoEl) versoEl.src = defaultSrc;
  }

  // Remettre à jour l'aperçu dans les paramètres
  const previewId = side === 'recto' ? 'previewTemplateRecto' : 'previewTemplateVerso';
  const preview = document.getElementById(previewId);
  if (preview) preview.src = defaultSrc;

  // Remettre le statut à "Standard"
  const boxId = side === 'recto' ? 'templateBoxRecto' : 'templateBoxVerso';
  const statusId = side === 'recto' ? 'statusBadgeRecto' : 'statusBadgeVerso';
  const btnResetId = side === 'recto' ? 'btnResetTemplateRecto' : 'btnResetTemplateVerso';
  const box = document.getElementById(boxId);
  const status = document.getElementById(statusId);
  const btnReset = document.getElementById(btnResetId);
  if (box) box.classList.remove('has-custom');
  if (status) { status.textContent = 'Modèle Standard'; status.className = 'template-status-badge status-default'; }
  if (btnReset) btnReset.style.display = 'none';

  // Rafraîchir le canvas si nécessaire
  if (side === 'recto' && activeBadgeSide === 'recto') {
    await renderOfficialBadgeCanvas();
  }

  toast(`Modèle ${side === 'recto' ? 'Recto' : 'Verso'} réinitialisé au modèle officiel.`, 'info');
}

window.triggerTemplateUpload = triggerTemplateUpload;
window.onTemplateFileInputChange = onTemplateFileInputChange;
window.resetBadgeTemplate = resetBadgeTemplate;

/**
 * Initialise le drag & drop sur les zones de templates de badges.
 * Appelé une fois dans DOMContentLoaded.
 */
function initTemplateDragDrop() {
  ['recto', 'verso'].forEach(side => {
    const zoneId = side === 'recto' ? 'dropZoneRecto' : 'dropZoneVerso';
    const zone = document.getElementById(zoneId);
    if (!zone) return;

    zone.addEventListener('dragover', e => {
      e.preventDefault();
      zone.classList.add('dragover');
    });
    zone.addEventListener('dragleave', () => {
      zone.classList.remove('dragover');
    });
    zone.addEventListener('drop', async e => {
      e.preventDefault();
      zone.classList.remove('dragover');
      const file = e.dataTransfer.files?.[0];
      if (!file || !file.type.startsWith('image/')) {
        toast('Veuillez déposer une image (PNG, JPG ou WebP).', 'error');
        return;
      }
      // Simuler l'événement change du file input
      const syntheticEvent = { target: { files: [file], value: '' } };
      await onTemplateFileInputChange(syntheticEvent, side);
    });
  });
}

// Initialiser le drag & drop une fois le DOM prêt
document.addEventListener('DOMContentLoaded', () => {
  // Petit délai pour s'assurer que initBadgeStudio() est terminé
  setTimeout(initTemplateDragDrop, 500);
});
