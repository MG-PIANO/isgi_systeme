const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronAPI', {
  // Window controls
  minimize: () => ipcRenderer.send('window-minimize'),
  maximize: () => ipcRenderer.send('window-maximize'),
  close: () => ipcRenderer.send('window-close'),
  setTheme: (theme) => ipcRenderer.send('set-theme', theme),

  // Badge photo
  selectPhoto: () => ipcRenderer.invoke('select-photo'),

  // PDF & Image
  savePDF: (data) => ipcRenderer.invoke('save-pdf', data),
  saveImage: (data) => ipcRenderer.invoke('save-image', data),
  savePDFBatch: (data) => ipcRenderer.invoke('save-pdf-batch', data),
  openPDF: (filePath) => ipcRenderer.invoke('open-pdf', filePath),

  // Vidéos
  selectVideo: () => ipcRenderer.invoke('select-video'),
  uploadVideo: (data) => ipcRenderer.invoke('upload-video', data),

  // Dossier
  selectOutputDir: () => ipcRenderer.invoke('select-output-dir'),

  // App info
  getVersion: () => ipcRenderer.invoke('get-app-version'),
  getAppPath: () => ipcRenderer.invoke('get-app-path'),

  // Templates & QR
  getTemplateImages: () => ipcRenderer.invoke('get-template-images'),
  saveCustomTemplate: (side, dataBase64) => ipcRenderer.invoke('save-custom-template', { side, dataBase64 }),
  resetCustomTemplate: (side) => ipcRenderer.invoke('reset-custom-template', { side }),
  selectTemplateImage: () => ipcRenderer.invoke('select-template-image'),
  generateQR: (text, options) => ipcRenderer.invoke('generate-qr', { text, options }),
});
