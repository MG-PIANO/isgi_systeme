const { app, BrowserWindow, ipcMain, dialog, shell, nativeTheme } = require('electron');
const path = require('path');
const fs = require('fs');
const os = require('os');

nativeTheme.themeSource = 'dark';

let mainWindow;

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
  return result.filePaths[0];
});

// ─── IPC: Lire fichier vidéo local ────────────────────────────────────────────
ipcMain.handle('read-video', async (event, filePath) => {
  if (!fs.existsSync(filePath)) return null;
  const stats = fs.statSync(filePath);
  return { exists: true, size: stats.size, path: filePath };
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
