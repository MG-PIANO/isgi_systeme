const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronAPI', {
  // We can add IPC communication here if needed later
  onOnlineStatusChange: (callback) => ipcRenderer.on('online-status-change', callback),
});
