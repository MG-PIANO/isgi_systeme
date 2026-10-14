import React, { useState, useMemo } from 'react';
import { Search, UserCheck, AlertCircle, Camera, CheckCircle2, ChevronRight, Filter, X } from 'lucide-react';
import type { Student, FilterStatus } from '../types/student';

interface StudentSelectorProps {
  students: Student[];
  selectedStudent: Student | null;
  onSelectStudent: (student: Student) => void;
  onStartCameraForStudent: (student: Student) => void;
  isLoading: boolean;
}

export const StudentSelector: React.FC<StudentSelectorProps> = ({
  students,
  selectedStudent,
  onSelectStudent,
  onStartCameraForStudent,
  isLoading
}) => {
  const [search, setSearch] = useState('');
  const [filterStatus, setFilterStatus] = useState<FilterStatus>('no-photo'); // Default to 'no-photo' for studio work!
  const [selectedFiliere, setSelectedFiliere] = useState<string>('all');
  const [selectedNiveau, setSelectedNiveau] = useState<string>('all');

  // Available filières & niveaux
  const { filieres, niveaux, noPhotoCount, hasPhotoCount } = useMemo(() => {
    const fSet = new Set<string>();
    const nSet = new Set<string>();
    let noPhoto = 0;
    let hasPhoto = 0;

    for (const s of students) {
      if (s.filiere) fSet.add(s.filiere);
      if (s.niveau) nSet.add(s.niveau);
      const hasPic = Boolean(s.photo_url || s.photo_cached);
      if (hasPic) hasPhoto++;
      else noPhoto++;
    }

    return {
      filieres: Array.from(fSet).sort(),
      niveaux: Array.from(nSet).sort(),
      noPhotoCount: noPhoto,
      hasPhotoCount: hasPhoto
    };
  }, [students]);

  // Filtered students
  const filteredStudents = useMemo(() => {
    const q = search.trim().toLowerCase();

    return students.filter(s => {
      // Status filter
      const hasPic = Boolean(s.photo_url || s.photo_cached);
      if (filterStatus === 'no-photo' && hasPic) return false;
      if (filterStatus === 'has-photo' && !hasPic) return false;

      // Filière filter
      if (selectedFiliere !== 'all' && s.filiere !== selectedFiliere) return false;

      // Niveau filter
      if (selectedNiveau !== 'all' && s.niveau !== selectedNiveau) return false;

      // Search query
      if (!q) return true;
      const fullName = `${s.nom} ${s.prenom}`.toLowerCase();
      const mat = (s.matricule || '').toLowerCase();
      const fil = (s.filiere || '').toLowerCase();
      const niv = (s.niveau || '').toLowerCase();

      return fullName.includes(q) || mat.includes(q) || fil.includes(q) || niv.includes(q);
    });
  }, [students, search, filterStatus, selectedFiliere, selectedNiveau]);

  return (
    <div className="flex flex-col h-full space-y-3">
      {/* Search Bar */}
      <div className="relative">
        <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
        <input
          type="text"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Rechercher par nom, matricule, filière..."
          className="w-full pl-10 pr-9 py-2.5 bg-slate-900/90 border border-slate-750 rounded-xl text-sm text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500 transition-all shadow-inner"
        />
        {search && (
          <button
            onClick={() => setSearch('')}
            className="absolute right-3 top-1/2 -translate-y-1/2 p-0.5 rounded-full text-slate-400 hover:text-white"
          >
            <X className="w-4 h-4" />
          </button>
        )}
      </div>

      {/* Quick Filter Tabs */}
      <div className="grid grid-cols-3 gap-1.5 p-1 bg-slate-900/80 rounded-xl border border-slate-800 text-xs font-medium">
        <button
          onClick={() => setFilterStatus('all')}
          className={`py-1.5 rounded-lg text-center transition-all ${
            filterStatus === 'all'
              ? 'bg-blue-600 text-white shadow-sm font-semibold'
              : 'text-slate-400 hover:text-slate-200'
          }`}
        >
          Tous ({students.length})
        </button>
        <button
          onClick={() => setFilterStatus('no-photo')}
          className={`py-1.5 rounded-lg text-center flex items-center justify-center gap-1 transition-all ${
            filterStatus === 'no-photo'
              ? 'bg-amber-600 text-white shadow-sm font-semibold'
              : 'text-amber-400/90 hover:text-amber-300'
          }`}
        >
          <AlertCircle className="w-3.5 h-3.5" />
          Sans photo ({noPhotoCount})
        </button>
        <button
          onClick={() => setFilterStatus('has-photo')}
          className={`py-1.5 rounded-lg text-center flex items-center justify-center gap-1 transition-all ${
            filterStatus === 'has-photo'
              ? 'bg-emerald-600 text-white shadow-sm font-semibold'
              : 'text-slate-400 hover:text-slate-200'
          }`}
        >
          <CheckCircle2 className="w-3.5 h-3.5" />
          Avec photo ({hasPhotoCount})
        </button>
      </div>

      {/* Secondary Dropdowns (Filière & Niveau) */}
      <div className="flex gap-2 text-xs">
        <select
          value={selectedFiliere}
          onChange={(e) => setSelectedFiliere(e.target.value)}
          className="flex-1 bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1.5 text-slate-300 focus:outline-none focus:ring-1 focus:ring-blue-500"
        >
          <option value="all">Toutes filières</option>
          {filieres.map(f => (
            <option key={f} value={f}>{f}</option>
          ))}
        </select>
        <select
          value={selectedNiveau}
          onChange={(e) => setSelectedNiveau(e.target.value)}
          className="w-32 bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1.5 text-slate-300 focus:outline-none focus:ring-1 focus:ring-blue-500"
        >
          <option value="all">Tous niveaux</option>
          {niveaux.map(n => (
            <option key={n} value={n}>{n}</option>
          ))}
        </select>
      </div>

      {/* Students Scrollable List */}
      <div className="flex-1 overflow-y-auto space-y-2 pr-1 min-h-[300px]">
        {isLoading ? (
          <div className="flex flex-col items-center justify-center py-16 text-slate-400 space-y-3">
            <div className="w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
            <p className="text-sm">Chargement des étudiants...</p>
          </div>
        ) : filteredStudents.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-12 text-center text-slate-400 px-4">
            <div className="w-12 h-12 rounded-2xl bg-slate-800/60 flex items-center justify-center mb-3 text-slate-500">
              <UserCheck className="w-6 h-6" />
            </div>
            <p className="text-sm font-medium text-slate-300">Aucun étudiant trouvé</p>
            <p className="text-xs text-slate-500 mt-1">
              Modifiez la recherche ou les filtres pour afficher des résultats.
            </p>
          </div>
        ) : (
          filteredStudents.map((student) => {
            const isSelected = selectedStudent?.id === student.id;
            const hasPhoto = Boolean(student.photo_url || student.photo_cached);
            const initials = `${(student.nom[0] || '').toUpperCase()}${(student.prenom[0] || '').toUpperCase()}`;

            return (
              <div
                key={student.id}
                onClick={() => onSelectStudent(student)}
                className={`group relative p-3 rounded-2xl border transition-all cursor-pointer flex items-center justify-between gap-3 ${
                  isSelected
                    ? 'bg-blue-950/40 border-blue-500 shadow-md shadow-blue-950/30 ring-1 ring-blue-500/50'
                    : 'bg-slate-900/60 hover:bg-slate-850 border-slate-800/80 hover:border-slate-700'
                }`}
              >
                {/* Left: Avatar + Details */}
                <div className="flex items-center gap-3 min-w-0">
                  {/* Photo or Placeholder */}
                  <div className="relative shrink-0">
                    <div className="w-12 h-12 rounded-xl overflow-hidden bg-slate-800 border border-slate-700/60 flex items-center justify-center font-bold text-sm text-slate-300">
                      {student.photo_cached ? (
                        <img
                          src={student.photo_cached}
                          alt={student.nom}
                          className="w-full h-full object-cover"
                        />
                      ) : hasPhoto ? (
                        <div className="w-full h-full bg-emerald-950/60 flex items-center justify-center text-emerald-400">
                          <CheckCircle2 className="w-6 h-6" />
                        </div>
                      ) : (
                        <span>{initials}</span>
                      )}
                    </div>
                    {/* Status Dot */}
                    <span
                      className={`absolute -bottom-1 -right-1 w-3.5 h-3.5 rounded-full border-2 border-slate-900 ${
                        hasPhoto ? 'bg-emerald-500' : 'bg-amber-500'
                      }`}
                      title={hasPhoto ? 'Photo enregistrée' : 'Photo manquante'}
                    />
                  </div>

                  {/* Info */}
                  <div className="min-w-0">
                    <h3 className="font-semibold text-sm text-white truncate leading-snug">
                      {student.nom.toUpperCase()} {student.prenom}
                    </h3>
                    <div className="flex flex-wrap items-center gap-1.5 mt-0.5">
                      <span className="font-mono text-[11px] font-bold text-amber-400 bg-amber-500/10 px-1.5 py-0.2 rounded border border-amber-500/20">
                        {student.matricule || 'N/A'}
                      </span>
                      {student.filiere && (
                        <span className="text-[11px] text-slate-300 bg-slate-800 px-1.5 py-0.2 rounded border border-slate-750 truncate max-w-[120px]">
                          {student.filiere}
                        </span>
                      )}
                      {student.niveau && (
                        <span className="text-[10px] text-slate-400 bg-slate-800/60 px-1 py-0.2 rounded">
                          {student.niveau}
                        </span>
                      )}
                    </div>
                  </div>
                </div>

                {/* Right: Quick Action Button */}
                <div className="flex items-center gap-1.5 shrink-0">
                  <button
                    onClick={(e) => {
                      e.stopPropagation();
                      onStartCameraForStudent(student);
                    }}
                    className="flex items-center gap-1 px-3 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold shadow-sm active:scale-95 transition-all"
                    title="Prendre la photo"
                  >
                    <Camera className="w-3.5 h-3.5" />
                    <span className="hidden sm:inline">Photo</span>
                  </button>
                </div>
              </div>
            );
          })
        )}
      </div>
    </div>
  );
};
