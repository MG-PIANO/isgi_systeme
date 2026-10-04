// Helper utilities for Messenger file attachments (photos, vidéos, pdf, docx, xls, etc.)

export function formatFileSize(bytes?: number): string {
  if (!bytes || bytes === 0) return '0 Ko';
  const k = 1024;
  const sizes = ['Octets', 'Ko', 'Mo', 'Go'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

export function detectFileType(file: File): 'image' | 'video' | 'audio' | 'document' {
  const mime = file.type.toLowerCase();
  const name = file.name.toLowerCase();

  if (mime.startsWith('image/') || /\.(jpg|jpeg|png|gif|webp|svg)$/i.test(name)) {
    return 'image';
  }
  if (mime.startsWith('video/') || /\.(mp4|webm|ogg|mov|mkv)$/i.test(name)) {
    return 'video';
  }
  if (mime.startsWith('audio/') || /\.(mp3|wav|ogg|m4a|aac)$/i.test(name)) {
    return 'audio';
  }
  return 'document';
}

export interface FileBadgeInfo {
  label: string;
  bgColor: string;
  textColor: string;
  borderColor: string;
  category: 'pdf' | 'word' | 'excel' | 'powerpoint' | 'image' | 'video' | 'archive' | 'file';
}

export function getFileBadgeInfo(filename?: string, mimeType?: string): FileBadgeInfo {
  const name = (filename || '').toLowerCase();
  const mime = (mimeType || '').toLowerCase();

  if (name.endsWith('.pdf') || mime.includes('pdf')) {
    return {
      label: 'PDF',
      bgColor: 'bg-rose-500/10',
      textColor: 'text-rose-600 dark:text-rose-400',
      borderColor: 'border-rose-500/20',
      category: 'pdf'
    };
  }

  if (name.endsWith('.docx') || name.endsWith('.doc') || mime.includes('word') || mime.includes('msword')) {
    return {
      label: 'DOCX',
      bgColor: 'bg-blue-500/10',
      textColor: 'text-blue-600 dark:text-blue-400',
      borderColor: 'border-blue-500/20',
      category: 'word'
    };
  }

  if (name.endsWith('.xlsx') || name.endsWith('.xls') || name.endsWith('.csv') || mime.includes('spreadsheet') || mime.includes('excel')) {
    return {
      label: 'EXCEL',
      bgColor: 'bg-emerald-500/10',
      textColor: 'text-emerald-600 dark:text-emerald-400',
      borderColor: 'border-emerald-500/20',
      category: 'excel'
    };
  }

  if (name.endsWith('.pptx') || name.endsWith('.ppt') || mime.includes('presentation')) {
    return {
      label: 'PPT',
      bgColor: 'bg-amber-500/10',
      textColor: 'text-amber-600 dark:text-amber-400',
      borderColor: 'border-amber-500/20',
      category: 'powerpoint'
    };
  }

  if (name.endsWith('.zip') || name.endsWith('.rar') || name.endsWith('.7z') || mime.includes('zip') || mime.includes('compressed')) {
    return {
      label: 'ZIP',
      bgColor: 'bg-purple-500/10',
      textColor: 'text-purple-600 dark:text-purple-400',
      borderColor: 'border-purple-500/20',
      category: 'archive'
    };
  }

  if (/\.(jpg|jpeg|png|gif|webp)$/i.test(name) || mime.startsWith('image/')) {
    return {
      label: 'IMAGE',
      bgColor: 'bg-cyan-500/10',
      textColor: 'text-cyan-600 dark:text-cyan-400',
      borderColor: 'border-cyan-500/20',
      category: 'image'
    };
  }

  if (/\.(mp4|webm|mov)$/i.test(name) || mime.startsWith('video/')) {
    return {
      label: 'VIDEO',
      bgColor: 'bg-violet-500/10',
      textColor: 'text-violet-600 dark:text-violet-400',
      borderColor: 'border-violet-500/20',
      category: 'video'
    };
  }

  const ext = name.includes('.') ? name.split('.').pop()!.toUpperCase() : 'FICHIER';
  return {
    label: ext.slice(0, 5),
    bgColor: 'bg-slate-500/10',
    textColor: 'text-slate-600 dark:text-slate-400',
    borderColor: 'border-slate-500/20',
    category: 'file'
  };
}

export function fileToBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result as string);
    reader.onerror = (err) => reject(err);
    reader.readAsDataURL(file);
  });
}

export function downloadAttachment(url: string, filename: string) {
  const a = document.createElement('a');
  a.href = url;
  a.download = filename || 'document_isgi';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}
