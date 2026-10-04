const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronAPI', {
  // Window controls
  minimize: () => ipcRenderer.send('window-minimize'),
  maximize: () => ipcRenderer.send('window-maximize'),
  close: () => ipcRenderer.send('window-close'),

  // Badge photo
  selectPhoto: () => ipcRenderer.invoke('select-photo'),

  // PDF & Image
  savePDF: (data) => ipcRenderer.invoke('save-pdf', data),
  saveImage: (data) => ipcRenderer.invoke('save-image', data),
  savePDFBatch: (data) => ipcRenderer.invoke('save-pdf-batch', data),
  openPDF: (filePath) => ipcRenderer.invoke('open-pdf', filePath),

  // Vidéos
  selectVideo: () => ipcRenderer.invoke('select-video'),
  readVideo: (path) => ipcRenderer.invoke('read-video', path),

  // Dossier
  selectOutputDir: () => ipcRenderer.invoke('select-output-dir'),

  // App info
  getVersion: () => ipcRenderer.invoke('get-app-version'),
  getAppPath: () => ipcRenderer.invoke('get-app-path'),
});
