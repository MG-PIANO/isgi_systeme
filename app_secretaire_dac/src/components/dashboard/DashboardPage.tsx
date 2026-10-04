import React, { useEffect, useState } from 'react';
import {
  GraduationCap,
  School,
  BookOpen,
  ClipboardCheck,
  Award,
  ArrowUpRight,
  Users,
  CheckCircle2,
  Calendar,
  Sparkles,
  Clock,
  CalendarDays
} from 'lucide-react';
import { db } from '../../db/db';
import type { Etudiant, Classe } from '../../types';

interface DashboardProps {
  onNavigate: (tab: string) => void;
}

export const DashboardPage: React.FC<DashboardProps> = ({ onNavigate }) => {
  const [stats, setStats] = useState({
    totalEtudiants: 0,
    totalClasses: 0,
    totalMatieres: 0,
    tauxPresence: 95
  });
  const [recentEtudiants, setRecentEtudiants] = useState<Etudiant[]>([]);
  const [classesList, setClassesList] = useState<Classe[]>([]);

  useEffect(() => {
    loadData();
  }, []);

  const loadData = async () => {
    try {
      const etuds = await db.etudiants.toArray();
      const classes = await db.classes.toArray();
      const mats = await db.matieres.toArray();
      const presences = await db.presences.toArray();

      let tx = 95;
      if (presences.length > 0) {
        const presents = presences.filter(p => p.statut === 'present').length;
        tx = Math.round((presents / presences.length) * 100);
      }

      setStats({
        totalEtudiants: etuds.length,
        totalClasses: classes.length,
        totalMatieres: mats.length,
        tauxPresence: tx
      });

      setRecentEtudiants(etuds.slice(0, 6));
      setClassesList(classes);
    } catch (e) {
      console.error(e);
    }
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-2xl font-bold text-on-surface">Tableau de Bord</h2>
          <p className="text-on-surface-variant text-sm mt-1">Aperçu général de la scolarité et de la pédagogie</p>
        </div>

        <div className="flex flex-wrap items-center gap-2.5">
          <button
            onClick={() => onNavigate('calendrier')}
            className="flex items-center gap-2 bg-surface-container-highest text-on-surface hover:bg-surface-container-high px-3.5 py-2 rounded-full font-medium transition-colors text-xs border border-outline-variant"
          >
            <CalendarDays className="w-3.5 h-3.5 text-primary" />
            <span>Calendrier</span>
          </button>
          <button
            onClick={() => onNavigate('emploi_du_temps')}
            className="flex items-center gap-2 bg-surface-container-highest text-on-surface hover:bg-surface-container-high px-3.5 py-2 rounded-full font-medium transition-colors text-xs border border-outline-variant"
          >
            <Clock className="w-3.5 h-3.5 text-primary" />
            <span>Emploi du Temps</span>
          </button>
          <button
            onClick={() => onNavigate('classes')}
            className="flex items-center gap-2 bg-primary text-on-primary px-4 py-2 rounded-full font-medium hover:bg-primary/90 transition-colors text-xs shadow-xs"
          >
            <School className="w-3.5 h-3.5" />
            <span>Classes</span>
          </button>
          <button
            onClick={() => onNavigate('notes')}
            className="flex items-center gap-2 bg-surface-container-highest text-on-surface hover:bg-surface-container-high px-3.5 py-2 rounded-full font-medium transition-colors text-xs border border-outline-variant"
          >
            <Award className="w-3.5 h-3.5" />
            <span>Notes</span>
          </button>
        </div>
      </div>


      {/* KPI Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-surface-container p-5 rounded-2xl border border-outline-variant shadow-sm flex flex-col justify-between gap-3">
          <div className="flex justify-between items-start">
            <div className="w-10 h-10 bg-primary-container text-on-primary-container rounded-xl flex items-center justify-center">
              <GraduationCap className="w-5 h-5" />
            </div>
            <button
              onClick={() => onNavigate('etudiants')}
              className="text-primary hover:text-primary/80 text-xs font-semibold flex items-center gap-0.5"
            >
              <span>Voir</span>
              <ArrowUpRight className="w-3.5 h-3.5" />
            </button>
          </div>
          <div>
            <p className="text-on-surface-variant text-xs font-medium">Effectif Étudiants</p>
            <p className="text-2xl font-bold text-on-surface mt-1">{stats.totalEtudiants} inscrits</p>
          </div>
        </div>

        <div className="bg-surface-container p-5 rounded-2xl border border-outline-variant shadow-sm flex flex-col justify-between gap-3">
          <div className="flex justify-between items-start">
            <div className="w-10 h-10 bg-secondary-container text-on-secondary-container rounded-xl flex items-center justify-center">
              <School className="w-5 h-5" />
            </div>
            <button
              onClick={() => onNavigate('classes')}
              className="text-primary hover:text-primary/80 text-xs font-semibold flex items-center gap-0.5"
            >
              <span>Voir</span>
              <ArrowUpRight className="w-3.5 h-3.5" />
            </button>
          </div>
          <div>
            <p className="text-on-surface-variant text-xs font-medium">Classes & Promotions</p>
            <p className="text-2xl font-bold text-on-surface mt-1">{stats.totalClasses} classes</p>
          </div>
        </div>

        <div className="bg-surface-container p-5 rounded-2xl border border-outline-variant shadow-sm flex flex-col justify-between gap-3">
          <div className="flex justify-between items-start">
            <div className="w-10 h-10 bg-tertiary-container text-on-tertiary-container rounded-xl flex items-center justify-center">
              <BookOpen className="w-5 h-5" />
            </div>
            <button
              onClick={() => onNavigate('matieres')}
              className="text-primary hover:text-primary/80 text-xs font-semibold flex items-center gap-0.5"
            >
              <span>Voir</span>
              <ArrowUpRight className="w-3.5 h-3.5" />
            </button>
          </div>
          <div>
            <p className="text-on-surface-variant text-xs font-medium">Matières au Catalogue</p>
            <p className="text-2xl font-bold text-on-surface mt-1">{stats.totalMatieres} cours</p>
          </div>
        </div>

        <div className="bg-surface-container p-5 rounded-2xl border border-outline-variant shadow-sm flex flex-col justify-between gap-3">
          <div className="flex justify-between items-start">
            <div className="w-10 h-10 bg-primary-fixed text-on-primary-fixed rounded-xl flex items-center justify-center">
              <ClipboardCheck className="w-5 h-5" />
            </div>
            <button
              onClick={() => onNavigate('presences')}
              className="text-primary hover:text-primary/80 text-xs font-semibold flex items-center gap-0.5"
            >
              <span>Appel</span>
              <ArrowUpRight className="w-3.5 h-3.5" />
            </button>
          </div>
          <div>
            <p className="text-on-surface-variant text-xs font-medium">Taux d'Assiduité</p>
            <p className="text-2xl font-bold text-green-600 mt-1">{stats.tauxPresence}%</p>
          </div>
        </div>
      </div>

      {/* Sections Principales */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Classes actives */}
        <div className="lg:col-span-2 bg-surface-container p-6 rounded-2xl border border-outline-variant shadow-sm space-y-4">
          <div className="flex justify-between items-center pb-2 border-b border-outline-variant">
            <h3 className="font-bold text-base text-on-surface">Classes & Groupes Actifs</h3>
            <button
              onClick={() => onNavigate('classes')}
              className="text-xs font-semibold text-primary hover:underline"
            >
              Voir toutes les classes →
            </button>
          </div>

          <div className="space-y-3">
            {classesList.map(cls => (
              <div
                key={cls.id}
                onClick={() => onNavigate('classes')}
                className="p-4 rounded-xl bg-surface-container-lowest border border-outline-variant hover:border-primary transition cursor-pointer flex items-center justify-between"
              >
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-xl bg-secondary-container text-on-secondary-container flex items-center justify-center font-bold text-xs">
                    {cls.niveau}
                  </div>
                  <div>
                    <h4 className="font-bold text-sm text-on-surface">{cls.nom}</h4>
                    <p className="text-xs text-on-surface-variant">{cls.code} • {cls.filiere}</p>
                  </div>
                </div>

                <div className="text-right">
                  <span className="text-xs font-semibold text-on-surface">{cls.salle_principale || 'Salle standard'}</span>
                  <p className="text-[11px] text-on-surface-variant">Capacité : {cls.capacite_max || 40}</p>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Derniers Inscrits */}
        <div className="bg-surface-container p-6 rounded-2xl border border-outline-variant shadow-sm space-y-4">
          <div className="flex justify-between items-center pb-2 border-b border-outline-variant">
            <h3 className="font-bold text-base text-on-surface">Derniers Inscrits</h3>
            <button
              onClick={() => onNavigate('etudiants')}
              className="text-xs font-semibold text-primary hover:underline"
            >
              Tous →
            </button>
          </div>

          <div className="space-y-3">
            {recentEtudiants.map(etud => (
              <div
                key={etud.id}
                className="p-3 rounded-xl bg-surface-container-lowest border border-outline-variant flex items-center justify-between"
              >
                <div className="flex items-center gap-3">
                  <div className="w-8 h-8 rounded-full bg-primary/10 text-primary font-bold text-xs flex items-center justify-center">
                    {etud.nom?.[0] || 'E'}
                  </div>
                  <div>
                    <p className="text-xs font-bold text-on-surface leading-tight">
                      {etud.nom.toUpperCase()} {etud.prenom}
                    </p>
                    <p className="text-[11px] text-on-surface-variant">{etud.matricule}</p>
                  </div>
                </div>
                <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-secondary-container text-on-secondary-container">
                  {etud.niveau}
                </span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
};
