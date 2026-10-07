const { app, BrowserWindow, ipcMain, dialog, shell, nativeTheme } = require('electron');
const path = require('path');
const fs = require('fs');
const os = require('os');
const https = require('https');
let QRCode;
try {
  QRCode = require('qrcode');
} catch (e) {
  QRCode = null;
}

nativeTheme.themeSource = 'dark';

let mainWindow;
const selectedVideoPaths = new Set();

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1400,
    height: 900,
    minWidth: 1100,
    minHeight: 700,
    frame: false,
    titleBarStyle: 'hidden',
    backgroundColor: '#0f1117',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      webSecurity: false,
    },
    icon: path.join(__dirname, 'assets', 'icon.ico'),
    show: false,
  });

  mainWindow.loadFile(path.join(__dirname, 'renderer', 'index.html'));

  mainWindow.once('ready-to-show', () => {
    mainWindow.show();
  });

  mainWindow.webContents.on('did-fail-load', () => {
    mainWindow.loadFile(path.join(__dirname, 'renderer', 'index.html'));
  });
}

app.whenReady().then(createWindow);

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});

// ─── IPC: Window Controls ─────────────────────────────────────────────────────
ipcMain.on('window-minimize', () => mainWindow.minimize());
ipcMain.on('window-maximize', () => {
  if (mainWindow.isMaximized()) mainWindow.unmaximize();
  else mainWindow.maximize();
});
ipcMain.on('window-close', () => mainWindow.close());
ipcMain.on('set-theme', (event, theme) => {
  nativeTheme.themeSource = theme === 'light' ? 'light' : 'dark';
});

// ─── IPC: Sélection photo étudiant ────────────────────────────────────────────
ipcMain.handle('select-photo', async () => {
  const result = await dialog.showOpenDialog(mainWindow, {
    title: 'Sélectionner la photo de l\'étudiant',
    filters: [{ name: 'Images', extensions: ['jpg', 'jpeg', 'png', 'webp'] }],
    properties: ['openFile'],
  });
  if (result.canceled || result.filePaths.length === 0) return null;
  const filePath = result.filePaths[0];
  const data = fs.readFileSync(filePath);
  return { path: filePath, base64: data.toString('base64'), ext: path.extname(filePath).slice(1) };
});

// ─── IPC: Sauvegarder PDF badge ────────────────────────────────────────────────
ipcMain.handle('save-pdf', async (event, { pdfBase64, studentName }) => {
  const defaultName = `Badge_${studentName.replace(/\s+/g, '_')}.pdf`;
  const result = await dialog.showSaveDialog(mainWindow, {
    title: 'Enregistrer le badge PDF',
    defaultPath: path.join(os.homedir(), 'Desktop', defaultName),
    filters: [{ name: 'PDF', extensions: ['pdf'] }],
  });
  if (result.canceled) return { success: false };
  const buffer = Buffer.from(pdfBase64, 'base64');
  fs.writeFileSync(result.filePath, buffer);
  return { success: true, filePath: result.filePath };
});

// ─── IPC: Sauvegarder Image PNG badge ──────────────────────────────────────────
ipcMain.handle('save-image', async (event, { imageBase64, studentName }) => {
  const defaultName = `Badge_${(studentName || 'ISGI').replace(/\s+/g, '_')}.png`;
  const result = await dialog.showSaveDialog(mainWindow, {
    title: 'Enregistrer le badge PNG HD',
    defaultPath: path.join(os.homedir(), 'Desktop', defaultName),
    filters: [{ name: 'Image PNG', extensions: ['png'] }],
  });
  if (result.canceled) return { success: false };
  const base64Data = imageBase64.replace(/^data:image\/\w+;base64,/, '');
  const buffer = Buffer.from(base64Data, 'base64');
  fs.writeFileSync(result.filePath, buffer);
  return { success: true, filePath: result.filePath };
});

// ─── IPC: Ouvrir PDF dans le viewer système ────────────────────────────────────
ipcMain.handle('open-pdf', async (event, filePath) => {
  await shell.openPath(filePath);
  return true;
});

// ─── IPC: Sauvegarder badges en lot ───────────────────────────────────────────
ipcMain.handle('save-pdf-batch', async (event, { pdfBase64, batchName }) => {
  const result = await dialog.showSaveDialog(mainWindow, {
    title: 'Enregistrer les badges (lot)',
    defaultPath: path.join(os.homedir(), 'Desktop', `Badges_${batchName}.pdf`),
    filters: [{ name: 'PDF', extensions: ['pdf'] }],
  });
  if (result.canceled) return { success: false };
  const buffer = Buffer.from(pdfBase64, 'base64');
  fs.writeFileSync(result.filePath, buffer);
  shell.showItemInFolder(result.filePath);
  return { success: true, filePath: result.filePath };
});

// ─── IPC: Sélection vidéo ─────────────────────────────────────────────────────
ipcMain.handle('select-video', async () => {
  const result = await dialog.showOpenDialog(mainWindow, {
    title: 'Sélectionner une vidéo',
    filters: [{ name: 'Vidéos', extensions: ['mp4', 'webm', 'mkv', 'avi', 'mov'] }],
    properties: ['openFile'],
  });
  if (result.canceled || result.filePaths.length === 0) return null;
  const filePath = path.resolve(result.filePaths[0]);
  selectedVideoPaths.add(filePath);
  return filePath;
});

ipcMain.handle('upload-video', async (event, { filePath, objectPath, userId, accessToken, contentType }) => {
  const resolvedPath = path.resolve(filePath);
  if (!selectedVideoPaths.has(resolvedPath)) throw new Error('Le fichier vidéo doit être sélectionné dans l’application.');
  if (!/^[a-zA-Z0-9_-]+\/[a-zA-Z0-9._-]+$/.test(objectPath) || objectPath.split('/')[0] !== userId) {
    throw new Error('Chemin de stockage vidéo invalide.');
  }
  if (!/^video\/(mp4|webm|x-matroska|avi|quicktime|ogg)$/.test(contentType)) throw new Error('Format vidéo non pris en charge.');
  if (!accessToken) throw new Error('Session Supabase manquante.');

  const stats = fs.statSync(resolvedPath);
  if (!stats.isFile() || stats.size > 5 * 1024 * 1024 * 1024) throw new Error('Fichier vidéo invalide ou supérieur à 5 Go.');
  const storagePath = objectPath.split('/').map(encodeURIComponent).join('/');

  await new Promise((resolve, reject) => {
    const request = https.request({
      hostname: 'vbdhmgrysrerlmgumafx.supabase.co',
      path: `/storage/v1/object/videos-isgi/${storagePath}`,
      method: 'POST',
      headers: {
        apikey: 'sb_publishable_sqJUSK-p5mF2Acy_bhxhAQ_nt_t6Fax',
        Authorization: `Bearer ${accessToken}`,
        'Content-Type': contentType,
        'Content-Length': stats.size,
        'x-upsert': 'false'
      }
    }, response => {
      let body = '';
      response.setEncoding('utf8');
      response.on('data', chunk => { body += chunk; });
      response.on('end', () => {
        if (response.statusCode < 200 || response.statusCode >= 300) {
          reject(new Error(`Échec du téléversement vidéo (HTTP ${response.statusCode}) : ${body}`));
          return;
        }
        resolve();
      });
    });
    request.setTimeout(30 * 60 * 1000, () => request.destroy(new Error('Délai dépassé pendant le téléversement vidéo.')));
    request.on('error', reject);
    const stream = fs.createReadStream(resolvedPath);
    stream.on('error', reject);
    stream.pipe(request);
  });

  selectedVideoPaths.delete(resolvedPath);
  return { storagePath, size: stats.size };
});

// ─── IPC: Dossier de sauvegarde badges ────────────────────────────────────────
ipcMain.handle('select-output-dir', async () => {
  const result = await dialog.showOpenDialog(mainWindow, {
    title: 'Choisir le dossier de destination',
    properties: ['openDirectory', 'createDirectory'],
  });
  if (result.canceled) return null;
  return result.filePaths[0];
});

// ─── IPC: App version ─────────────────────────────────────────────────────────
ipcMain.handle('get-app-version', () => app.getVersion());
ipcMain.handle('get-app-path', () => app.getPath('userData'));

// ─── IPC: Génération QR Code native hors-ligne ────────────────────────────────
ipcMain.handle('generate-qr', async (event, { text, options }) => {
  if (!QRCode) return null;
  return QRCode.toDataURL(text, options || {
    width: 200,
    margin: 1,
    color: { dark: '#003087', light: '#ffffff' }
  });
});

// ─── IPC: Modèles de badges officiels et personnalisés (Recto / Verso) ───
ipcMain.handle('get-template-images', async () => {
  const customRectoPath = path.join(app.getPath('userData'), 'custom_badge_recto.png');
  const customVersoPath = path.join(app.getPath('userData'), 'custom_badge_verso.png');
  const defaultRectoPath = path.join(__dirname, 'renderer', 'images', 'badge_recto.png');
  const defaultVersoPath = path.join(__dirname, 'renderer', 'images', 'badge_verso.png');

  const hasCustomRecto = fs.existsSync(customRectoPath);
  const hasCustomVerso = fs.existsSync(customVersoPath);

  const rectoPath = hasCustomRecto ? customRectoPath : defaultRectoPath;
  const versoPath = hasCustomVerso ? customVersoPath : defaultVersoPath;

  return {
    recto: fs.existsSync(rectoPath) ? `data:image/png;base64,${fs.readFileSync(rectoPath).toString('base64')}` : null,
    verso: fs.existsSync(versoPath) ? `data:image/png;base64,${fs.readFileSync(versoPath).toString('base64')}` : null,
    isCustomRecto: hasCustomRecto,
    isCustomVerso: hasCustomVerso
  };
});

ipcMain.handle('save-custom-template', async (event, { side, dataBase64 }) => {
  if (side !== 'recto' && side !== 'verso') throw new Error('Face de badge invalide.');
  const base64Data = dataBase64.replace(/^data:image\/\w+;base64,/, '');
  const buffer = Buffer.from(base64Data, 'base64');
  const targetPath = path.join(app.getPath('userData'), `custom_badge_${side}.png`);
  fs.writeFileSync(targetPath, buffer);
  return { success: true, side, url: `data:image/png;base64,${base64Data}` };
});

ipcMain.handle('reset-custom-template', async (event, { side }) => {
  if (side !== 'recto' && side !== 'verso') throw new Error('Face de badge invalide.');
  const customPath = path.join(app.getPath('userData'), `custom_badge_${side}.png`);
  if (fs.existsSync(customPath)) {
    fs.unlinkSync(customPath);
  }
  const defaultPath = path.join(__dirname, 'renderer', 'images', `badge_${side}.png`);
  const defaultData = fs.existsSync(defaultPath)
    ? `data:image/png;base64,${fs.readFileSync(defaultPath).toString('base64')}`
    : null;
  return { success: true, side, defaultUrl: defaultData };
});

ipcMain.handle('select-template-image', async () => {
  const result = await dialog.showOpenDialog(mainWindow, {
    title: 'Sélectionner un nouveau modèle de badge',
    filters: [{ name: 'Images', extensions: ['png', 'jpg', 'jpeg', 'webp'] }],
    properties: ['openFile']
  });
  if (result.canceled || result.filePaths.length === 0) return null;
  const filePath = result.filePaths[0];
  const ext = path.extname(filePath).toLowerCase().replace('.', '') || 'png';
  const mime = ext === 'jpg' || ext === 'jpeg' ? 'image/jpeg' : (ext === 'webp' ? 'image/webp' : 'image/png');
  const buffer = fs.readFileSync(filePath);
  return `data:${mime};base64,${buffer.toString('base64')}`;
});

