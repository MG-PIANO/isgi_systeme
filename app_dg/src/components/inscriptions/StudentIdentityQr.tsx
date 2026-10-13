import { useEffect, useState } from 'react';
import QRCode from 'qrcode';

export interface StudentQrIdentity {
  matricule?: string | null;
  nom?: string | null;
  prenom?: string | null;
}

export function buildStudentQrPayload(student: StudentQrIdentity): string {
  const matricule = student.matricule?.trim().toUpperCase() || '';
  const nom = student.nom?.trim().toUpperCase() || '';
  const prenom = student.prenom?.trim() || '';
  if (!matricule || (!nom && !prenom)) return '';

  return JSON.stringify({
    type: 'ISGI_STUDENT',
    version: 1,
    matricule,
    nom,
    prenom
  });
}

export default function StudentIdentityQr({ student }: { student: StudentQrIdentity }) {
  const [dataUrl, setDataUrl] = useState('');
  const [error, setError] = useState('');
  const payload = buildStudentQrPayload(student);
  const matricule = student.matricule?.trim() || '';
  const filename = `QR_${matricule.replace(/[^a-zA-Z0-9_-]/g, '_')}.png`;

  useEffect(() => {
    let active = true;
    setDataUrl('');
    setError('');
    if (!payload) {
      setError('Un matricule et un nom ou prénom sont nécessaires pour générer le QR.');
      return () => {
        active = false;
      };
    }

    QRCode.toDataURL(payload, {
      width: 280,
      margin: 2,
      errorCorrectionLevel: 'M',
      color: { dark: '#003087', light: '#ffffff' }
    }).then(
      value => {
        if (active) setDataUrl(value);
      },
      generationError => {
        console.error('Impossible de générer le QR étudiant:', generationError);
        if (active) setError('Le code QR de cet étudiant n’a pas pu être généré.');
      }
    );

    return () => {
      active = false;
    };
  }, [payload]);

  return (
    <div className="flex flex-col items-center gap-3">
      {dataUrl ? (
        <>
          <img className="w-56 h-56 rounded-lg bg-white p-2" src={dataUrl} alt={`QR étudiant ${student.matricule}`} />
          <a
            className="px-4 py-2 rounded-full font-medium bg-primary text-on-primary hover:bg-primary/90 transition-colors"
            href={dataUrl}
            download={filename}
          >
            Télécharger le QR PNG
          </a>
        </>
      ) : error ? (
        <p role="alert" className="text-sm text-error">{error}</p>
      ) : (
        <p className="text-sm text-on-surface-variant">Génération du code QR…</p>
      )}
      <p className="text-sm font-semibold text-on-surface">{student.nom || ''} {student.prenom || ''}</p>
      <code className="text-xs text-on-surface-variant">{matricule}</code>
    </div>
  );
}
