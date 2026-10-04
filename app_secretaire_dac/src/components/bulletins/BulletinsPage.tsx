import React, { useState, useEffect, useMemo } from 'react';
import {
  FileSpreadsheet,
  Download,
  Search,
  Printer
} from 'lucide-react';
import { db } from '../../db/db';
import type { Classe, Matiere, Etudiant, Note, ClasseEtudiant, ClasseMatiere, Personnel } from '../../types';
import {
  exporterBulletinPDF,
  getMention,
  getAppreciation,
  type DonneesBulletin,
  type LigneBulletin
} from '../../utils/bulletinExport';
import {
  getSemestresForClasse,
  getDefaultSemestreForClasse,
  formatSemestreLabel
} from '../../utils/academicSemestres';

export const BulletinsPage: React.FC = () => {
  const [classes, setClasses] = useState<Classe[]>([]);
  const [matieres, setMatieres] = useState<Matiere[]>([]);
  const [etudiants, setEtudiants] = useState<Etudiant[]>([]);
  const [personnel, setPersonnel] = useState<Personnel[]>([]);
  const [classeEtudiants, setClasseEtudiants] = useState<ClasseEtudiant[]>([]);
  const [classeMatieres, setClasseMatieres] = useState<ClasseMatiere[]>([]);
  const [notes, setNotes] = useState<Note[]>([]);

  const [selectedClasseId, setSelectedClasseId] = useState('');
  const [selectedSemestre, setSelectedSemestre] = useState<string>('S1');
  const [selectedEtudiantId, setSelectedEtudiantId] = useState('');

  const [searchQuery, setSearchQuery] = useState('');
  const [generatingAll, setGeneratingAll] = useState(false);
  const [appSettings, setAppSettings] = useState<{ [key: string]: any }>({
    ville: 'Brazzaville',
    titreSignataire2: 'Le Directeur des Affaires Académiques (DAC)'
  });

  useEffect(() => {
    loadAllData();
    try {
      const saved = localStorage.getItem('isgi_settings');
      if (saved) {
        setAppSettings(prev => ({ ...prev, ...JSON.parse(saved) }));
      }
    } catch {}
  }, []);

  const loadAllData = async () => {
    try {
      const cls = await db.classes.toArray();
      const mats = await db.matieres.toArray();
      const etuds = await db.etudiants.toArray();
      const pers = await db.personnel.toArray();
      const cEtuds = await db.classe_etudiants.toArray();
      const cMats = await db.classe_matieres.toArray();
      const nts = await db.notes.toArray();

      setClasses(cls);
      setMatieres(mats);
      setEtudiants(etuds);
      setPersonnel(pers);
      setClasseEtudiants(cEtuds);
      setClasseMatieres(cMats);
      setNotes(nts);

      if (cls.length > 0) {
        setSelectedClasseId(cls[0].id);
        setSelectedSemestre(getDefaultSemestreForClasse(cls[0]));
      }
    } catch (e) {
      console.error(e);
    }
  };

  const activeClasse = classes.find(c => c.id === selectedClasseId);

  // Semestres autorisés et adaptés au niveau de la classe (L1=S1/S2, L2=S3/S4, L3=S5/S6, M1=S7/S8, M2=S9/S10)
  const semestresDisponibles = useMemo(() => {
    return getSemestresForClasse(activeClasse);
  }, [activeClasse]);

  // Synchroniser automatiquement le semestre lors d'un changement de classe
  useEffect(() => {
    if (semestresDisponibles.length > 0) {
      const exists = semestresDisponibles.some(s => s.value === selectedSemestre);
      if (!exists) {
        setSelectedSemestre(semestresDisponibles[0].value);
      }
    }
  }, [semestresDisponibles, selectedSemestre]);

  const matieresDeLaClasse = useMemo(() => {
    return classeMatieres
      .filter(cm => cm.classe_id === selectedClasseId)
      .map(cm => {
        const mat = matieres.find(m => m.id === cm.matiere_id);
        const prof = personnel.find(p => p.id === cm.enseignant_id);
        return {
          ...cm,
          matiere: mat,
          enseignant: prof
        };
      })
      .filter(item => item.matiere !== undefined);
  }, [classeMatieres, selectedClasseId, matieres, personnel]);

  const etudiantsDeLaClasse = useMemo(() => {
    return classeEtudiants
      .filter(ce => ce.classe_id === selectedClasseId && ce.statut === 'actif')
      .map(ce => etudiants.find(e => e.id === ce.etudiant_id))
      .filter(Boolean) as Etudiant[];
  }, [classeEtudiants, selectedClasseId, etudiants]);

  // Synchroniser l'étudiant sélectionné lorsque la liste d'étudiants change
  useEffect(() => {
    if (etudiantsDeLaClasse.length > 0) {
      const exists = etudiantsDeLaClasse.some(e => e.id === selectedEtudiantId);
      if (!exists) {
        setSelectedEtudiantId(etudiantsDeLaClasse[0].id);
      }
    } else {
      setSelectedEtudiantId('');
    }
  }, [etudiantsDeLaClasse, selectedEtudiantId]);

  const computeBulletinForStudent = (etud: Etudiant): DonneesBulletin | null => {
    if (!activeClasse) return null;

    let totalPoints = 0;
    let totalCredits = 0;
    let totalCreditsValides = 0;

    const lignes: LigneBulletin[] = matieresDeLaClasse.map(cm => {
      const mat = cm.matiere!;
      const noteRecord = notes.find(
        n =>
          n.classe_id === activeClasse.id &&
          n.matiere_id === mat.id &&
          n.etudiant_id === etud.id &&
          (n.semestre || '').toUpperCase() === (selectedSemestre || '').toUpperCase()
      );

      const noteCC = noteRecord?.note_cc ?? null;
      const noteExamen = noteRecord?.note_examen ?? null;
      const noteFinale = noteRecord?.note_finale ?? null;

      // Moyenne réelle de la matière (DST*20% + Recherche*20% + Session*60% ou note enregistrée)
      let noteMatiereMoyenne: number | null = null;
      if (noteRecord?.observations && !isNaN(parseFloat(noteRecord.observations))) {
        noteMatiereMoyenne = parseFloat(noteRecord.observations);
      } else if (noteFinale !== null) {
        const dst = noteCC ?? 0;
        const dr = noteExamen ?? 0;
        const sess = noteFinale;
        const base = Number((dst * 0.2 + dr * 0.2 + sess * 0.6).toFixed(2));
        noteMatiereMoyenne = noteRecord?.note_rattrapage !== undefined && noteRecord.note_rattrapage !== null
          ? Math.max(base, noteRecord.note_rattrapage)
          : base;
      }

      const credits = cm.credits || mat.credits || 1;
      let points: number | null = null;
      let decision: 'Validé' | 'Ajourné' | 'Non évalué' = 'Non évalué';

      const notePourCalcul = noteMatiereMoyenne !== null ? noteMatiereMoyenne : noteFinale;
      if (notePourCalcul !== null) {
        points = Number((notePourCalcul * credits).toFixed(2));
        totalPoints += points;
        totalCredits += credits;
        if (notePourCalcul >= 10) {
          totalCreditsValides += credits;
          decision = 'Validé';
        } else {
          decision = 'Ajourné';
        }
      }

      const profNom = cm.enseignant ? `${cm.enseignant.nom.toUpperCase()} ${cm.enseignant.prenom}` : 'Professeur';

      return {
        matiere: mat,
        enseignantNom: profNom,
        noteCC,
        noteExamen,
        noteFinale: notePourCalcul,
        points,
        decision
      };
    });

    const moyenneGenerale = totalCredits > 0 ? Number((totalPoints / totalCredits).toFixed(2)) : null;

    return {
      etudiant: etud,
      classe: activeClasse,
      semestre: formatSemestreLabel(selectedSemestre),
      anneeAcademique: activeClasse.annee_academique || '2025-2026',
      lignes,
      totalCredits,
      totalCreditsValides,
      moyenneGenerale,
      rang: 1,
      totalEtudiantsClasse: etudiantsDeLaClasse.length,
      mention: getMention(moyenneGenerale),
      appreciation: getAppreciation(moyenneGenerale)
    };
  };

  const classementClasse = useMemo(() => {
    const list = etudiantsDeLaClasse.map(e => {
      const b = computeBulletinForStudent(e);
      return {
        etudiant: e,
        moyenne: b?.moyenneGenerale ?? -1,
        bulletin: b
      };
    });

    list.sort((a, b) => b.moyenne - a.moyenne);

    return list.map((item, index) => ({
      ...item,
      rang: item.moyenne >= 0 ? index + 1 : 'N/A'
    }));
  }, [etudiantsDeLaClasse, matieresDeLaClasse, notes, selectedSemestre, activeClasse]);

  const currentBulletin = useMemo(() => {
    const target = etudiantsDeLaClasse.find(e => e.id === selectedEtudiantId) || etudiantsDeLaClasse[0];
    if (!target) return null;
    const b = computeBulletinForStudent(target);
    if (b) {
      const ranked = classementClasse.find(c => c.etudiant.id === target.id);
      b.rang = ranked ? ranked.rang : 1;
    }
    return b;
  }, [selectedEtudiantId, etudiantsDeLaClasse, classementClasse]);

  const handleDownloadCurrentPDF = async () => {
    if (!currentBulletin) return;
    await exporterBulletinPDF(currentBulletin);
  };

  const handleDownloadAllClassPDF = async () => {
    if (classementClasse.length === 0) return;
    setGeneratingAll(true);
    try {
      for (const item of classementClasse) {
        if (item.bulletin) {
          item.bulletin.rang = item.rang;
          await exporterBulletinPDF(item.bulletin);
        }
      }
    } catch (e) {
      console.error(e);
    } finally {
      setGeneratingAll(false);
    }
  };

  const filteredClassement = classementClasse.filter(c => {
    const q = searchQuery.toLowerCase().trim();
    return (
      !q ||
      c.etudiant.nom.toLowerCase().includes(q) ||
      c.etudiant.prenom.toLowerCase().includes(q) ||
      c.etudiant.matricule.toLowerCase().includes(q)
    );
  });

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-2xl font-bold text-on-surface">Bulletins & Délibérations</h2>
          <p className="text-on-surface-variant text-sm mt-1">
            Génération des relevés de notes officiels, calcul des rangs et mentions
          </p>
        </div>

        <button
          onClick={handleDownloadAllClassPDF}
          disabled={generatingAll || classementClasse.length === 0}
          className="flex items-center gap-2 bg-primary text-on-primary px-4 py-2 rounded-full font-medium text-sm hover:bg-primary/90 transition-colors disabled:opacity-50"
        >
          <Printer className="w-4 h-4" />
          <span>{generatingAll ? 'Génération...' : `Imprimer Tous (${classementClasse.length})`}</span>
        </button>
      </div>

      {/* Filtres */}
      <div className="bg-surface-container rounded-2xl border border-outline-variant p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label className="text-xs font-semibold text-on-surface block mb-1">Classe *</label>
          <select
            value={selectedClasseId}
            onChange={(e) => {
              const newId = e.target.value;
              setSelectedClasseId(newId);
              setSelectedEtudiantId('');
              const clsFound = classes.find(c => c.id === newId);
              if (clsFound) {
                const sems = getSemestresForClasse(clsFound);
                if (sems.length > 0) {
                  setSelectedSemestre(sems[0].value);
                }
              }
            }}
            className="w-full p-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none"
          >
            {classes.map(c => (
              <option key={c.id} value={c.id}>{c.nom} ({c.code})</option>
            ))}
          </select>
        </div>

        <div>
          <label className="text-xs font-semibold text-on-surface block mb-1">
            Semestre {activeClasse ? `(${activeClasse.niveau || activeClasse.nom})` : '*'}
          </label>
          <select
            value={selectedSemestre}
            onChange={(e) => setSelectedSemestre(e.target.value)}
            className="w-full p-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none font-medium"
          >
            {semestresDisponibles.map(s => (
              <option key={s.value} value={s.value}>
                {s.label}
              </option>
            ))}
          </select>
        </div>
      </div>

      {/* Grille 2 colonnes */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {/* Colonne gauche : Classement */}
        <div className="lg:col-span-5 bg-surface-container rounded-2xl border border-outline-variant p-5 space-y-4">
          <div className="flex justify-between items-center pb-2 border-b border-outline-variant">
            <h3 className="font-semibold text-sm text-on-surface">Classement de la Promotion</h3>
            <span className="text-xs text-on-surface-variant">{filteredClassement.length} élèves</span>
          </div>

          <div className="relative">
            <Search className="w-4 h-4 text-on-surface-variant absolute left-3 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              placeholder="Rechercher étudiant..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full pl-9 pr-3 py-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none"
            />
          </div>

          <div className="divide-y divide-outline-variant max-h-[460px] overflow-y-auto custom-scrollbar border rounded-xl border-outline-variant bg-surface-container-lowest">
            {filteredClassement.map((item) => {
              const isSelected = selectedEtudiantId === item.etudiant.id;
              const hasNotes = item.moyenne >= 0;
              return (
                <div
                  key={item.etudiant.id}
                  onClick={() => setSelectedEtudiantId(item.etudiant.id)}
                  className={`
                    p-3 flex justify-between items-center cursor-pointer transition-colors
                    ${isSelected ? 'bg-secondary-container text-on-secondary-container font-semibold' : 'hover:bg-surface-container-high/40 text-on-surface'}
                  `}
                >
                  <div className="flex items-center gap-3">
                    <span className={`w-6 text-center text-xs font-bold ${item.rang === 1 ? 'text-amber-500' : 'text-on-surface-variant'}`}>
                      {item.rang !== 'N/A' ? `${item.rang}e` : '-'}
                    </span>
                    <div>
                      <p className="text-xs font-bold leading-tight">
                        {item.etudiant.nom.toUpperCase()} {item.etudiant.prenom}
                      </p>
                      <p className="text-[11px] text-on-surface-variant">{item.etudiant.matricule}</p>
                    </div>
                  </div>

                  <div className="text-right text-xs">
                    {hasNotes ? (
                      <div>
                        <span className={`font-bold ${item.moyenne >= 10 ? 'text-green-600' : 'text-error'}`}>
                          {item.moyenne.toFixed(2)} / 20
                        </span>
                        <span className="block text-[10px] text-on-surface-variant">
                          {getMention(item.moyenne)}
                        </span>
                      </div>
                    ) : (
                      <span className="text-[10px] text-on-surface-variant italic">Non noté</span>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </div>

        {/* Colonne droite : Aperçu Bulletin */}
        <div className="lg:col-span-7">
          {currentBulletin ? (
            <div className="bg-surface-container rounded-2xl border border-outline-variant p-6 space-y-5">
              <div className="flex justify-between items-center pb-2 border-b border-outline-variant">
                <div>
                  <h3 className="font-bold text-base text-on-surface">Aperçu du Bulletin Officiel</h3>
                  <p className="text-xs text-on-surface-variant">{currentBulletin.semestre} • {currentBulletin.anneeAcademique}</p>
                </div>

                <button
                  onClick={handleDownloadCurrentPDF}
                  className="flex items-center gap-2 bg-primary text-on-primary px-4 py-2 rounded-full font-medium text-xs hover:bg-primary/90 transition-colors"
                >
                  <Download className="w-4 h-4" />
                  <span>Télécharger PDF</span>
                </button>
              </div>

              {/* Fiche Bulletin à l'écran */}
              <div className="bg-surface-container-lowest border border-outline-variant rounded-2xl p-5 space-y-4 text-xs">
                <div className="flex justify-between items-center pb-3 border-b border-outline-variant">
                  <div className="flex items-center gap-3">
                    <img src="./logo.jpg" alt="Logo" className="w-10 h-10 rounded-lg object-contain" />
                    <div>
                      <h4 className="font-bold text-sm text-on-surface">INSTITUT SUPÉRIEUR DE GESTION ET D'INGÉNIERIE</h4>
                      <p className="text-[10px] text-on-surface-variant uppercase">Direction des Affaires Académiques</p>
                    </div>
                  </div>
                  <span className="px-2.5 py-1 rounded-full bg-secondary-container text-on-secondary-container font-bold text-xs">
                    {currentBulletin.semestre}
                  </span>
                </div>

                <div className="grid grid-cols-2 gap-2 bg-surface-container p-3 rounded-xl border border-outline-variant">
                  <p><span className="text-on-surface-variant">Nom :</span> <strong className="text-on-surface">{currentBulletin.etudiant.nom.toUpperCase()} {currentBulletin.etudiant.prenom}</strong></p>
                  <p><span className="text-on-surface-variant">Classe :</span> <strong className="text-on-surface">{currentBulletin.classe.nom}</strong></p>
                  <p><span className="text-on-surface-variant">Matricule :</span> <strong className="text-primary font-mono">{currentBulletin.etudiant.matricule}</strong></p>
                  <p><span className="text-on-surface-variant">Filière :</span> <span className="text-on-surface">{currentBulletin.classe.filiere}</span></p>
                </div>

                {/* Tableau matières */}
                <div className="border border-outline-variant rounded-xl overflow-hidden">
                  <table className="w-full text-left">
                    <thead className="bg-surface-container-highest text-on-surface-variant font-semibold border-b border-outline-variant">
                      <tr>
                        <th className="p-2">Matière</th>
                        <th className="p-2 text-center">Créd.</th>
                        <th className="p-2 text-center">CC</th>
                        <th className="p-2 text-center">Exam</th>
                        <th className="p-2 text-center">Moy.</th>
                        <th className="p-2 text-center">Décision</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-outline-variant text-on-surface">
                      {currentBulletin.lignes.map(l => (
                        <tr key={l.matiere.id}>
                          <td className="p-2">
                            <p className="font-semibold">{l.matiere.nom}</p>
                            <p className="text-[10px] text-on-surface-variant">{l.matiere.code}</p>
                          </td>
                          <td className="p-2 text-center font-bold">{l.matiere.credits}</td>
                          <td className="p-2 text-center">{l.noteCC !== null ? l.noteCC.toFixed(2) : '-'}</td>
                          <td className="p-2 text-center">{l.noteExamen !== null ? l.noteExamen.toFixed(2) : '-'}</td>
                          <td className="p-2 text-center font-bold">
                            {l.noteFinale !== null ? l.noteFinale.toFixed(2) : '-'}
                          </td>
                          <td className="p-2 text-center">
                            <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold ${
                              l.decision === 'Validé' ? 'bg-secondary-container text-on-secondary-container' : 'text-on-surface-variant'
                            }`}>
                              {l.decision}
                            </span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                {/* Bilan */}
                <div className="bg-surface-container p-4 rounded-xl border border-outline-variant flex justify-between items-center">
                  <div className="space-y-1">
                    <p>• Rang : <strong className="text-primary">{currentBulletin.rang}e</strong> sur {currentBulletin.totalEtudiantsClasse} élèves</p>
                    <p>• Crédits validés : <strong>{currentBulletin.totalCreditsValides} / {currentBulletin.totalCredits}</strong></p>
                    <p>• Décision : <strong className={currentBulletin.moyenneGenerale && currentBulletin.moyenneGenerale >= 10 ? 'text-green-600' : 'text-error'}>
                      {currentBulletin.moyenneGenerale && currentBulletin.moyenneGenerale >= 10 ? 'ADMIS(E)' : 'AJOURNÉ(E)'}
                    </strong></p>
                  </div>

                  <div className="text-right bg-surface-container-lowest p-3 rounded-xl border border-outline-variant shadow-xs">
                    <span className="text-[10px] uppercase font-bold text-on-surface-variant block">Moyenne Générale</span>
                    <span className={`text-2xl font-bold ${
                      currentBulletin.moyenneGenerale && currentBulletin.moyenneGenerale >= 10 ? 'text-green-600' : 'text-error'
                    }`}>
                      {currentBulletin.moyenneGenerale !== null ? `${currentBulletin.moyenneGenerale.toFixed(2)} / 20` : 'N/A'}
                    </span>
                    <span className="block text-xs font-semibold text-on-surface-variant mt-0.5">
                      Mention : {currentBulletin.mention}
                    </span>
                  </div>
                </div>

                {/* Pied de page officiel du bulletin avec Ville & Signature */}
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center pt-3 border-t border-outline-variant text-xs text-on-surface-variant gap-3">
                  <div>
                    <p className="font-semibold text-on-surface flex items-center gap-1.5">
                      <span className="w-2 h-2 rounded-full bg-primary inline-block"></span>
                      Délivré à <strong className="text-primary font-bold">{appSettings.ville || 'Brazzaville'}</strong>, le {new Date().toLocaleDateString('fr-FR')}
                    </p>
                    <p className="text-[10px] text-on-surface-variant">Document officiel certifié conforme par la Direction des Affaires Académiques</p>
                  </div>
                  <div className="text-right">
                    <p className="font-bold text-on-surface">{appSettings.titreSignataire2 || 'La Direction des Affaires Académiques (DAC)'}</p>
                    <p className="text-[10px] italic text-on-surface-variant">(Cachet et Signature autorisée)</p>
                  </div>
                </div>
              </div>
            </div>
          ) : (
            <div className="p-12 text-center text-on-surface-variant bg-surface-container rounded-2xl border border-outline-variant">
              Sélectionnez un élève à gauche pour afficher son bulletin.
            </div>
          )}
        </div>
      </div>
    </div>
  );
};
