const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronAPI', {
  onOnlineStatusChange: (callback) => ipcRenderer.on('online-status-change', callback),
});
