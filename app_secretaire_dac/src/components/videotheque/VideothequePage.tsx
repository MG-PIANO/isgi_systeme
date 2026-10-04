import React, { useState, useEffect } from 'react';
import {
  Video,
  Play,
  Search,
  Filter,
  Globe,
  GraduationCap,
  Eye,
  Calendar,
  X,
  RefreshCw,
  FolderOpen
} from 'lucide-react';
import { supabase } from '../../db/supabaseClient';

export interface VideoItem {
  id: string;
  titre: string;
  description?: string;
  categorie: string;
  visibilite: 'publique' | 'classe';
  classe_id?: string;
  classe_nom?: string;
  classe_filiere?: string;
  classe_niveau?: string;
  url_video?: string;
  url_miniature?: string;
  duree_secondes?: number;
  publie_par_nom?: string;
  statut: string;
  vues: number;
  created_at: string;
}

export const VideothequePage: React.FC = () => {
  const [videos, setVideos] = useState<VideoItem[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [activeTab, setActiveTab] = useState<'all' | 'publique' | 'classe'>('all');
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [selectedClasse, setSelectedClasse] = useState<string>('');
  const [selectedCategorie, setSelectedCategorie] = useState<string>('all');
  const [activeVideo, setActiveVideo] = useState<VideoItem | null>(null);

  useEffect(() => {
    fetchVideos();

    const channel = supabase
      .channel('videos_channel_secretaire')
      .on('postgres_changes', { event: '*', schema: 'public', table: 'videos' }, () => {
        fetchVideos();
      })
      .subscribe();

    return () => {
      supabase.removeChannel(channel);
    };
  }, []);

  const fetchVideos = async () => {
    setLoading(true);
    try {
      const { data, error } = await supabase
        .from('videos')
        .select('*')
        .order('created_at', { ascending: false });

      if (!error && data) {
        setVideos(data as VideoItem[]);
      } else {
        const local = localStorage.getItem('isgi_videos');
        if (local) {
          try {
            const parsed = JSON.parse(local);
            setVideos(parsed.map((p: any) => ({
              id: p.id,
              titre: p.titre,
              description: p.desc,
              categorie: p.categorie || 'cours',
              visibilite: p.visibilite || 'publique',
              classe_id: p.classe_id,
              classe_nom: p.classe_nom,
              classe_filiere: p.classe_filiere,
              classe_niveau: p.classe_niveau,
              url_video: p.path,
              url_miniature: p.thumb,
              publie_par_nom: p.publie_par_nom || 'Service Informatique',
              statut: 'publie',
              vues: p.vues || 0,
              created_at: p.dateAjout || new Date().toISOString()
            })));
          } catch {}
        }
      }
    } catch {
      // Ignorer
    } finally {
      setLoading(false);
    }
  };

  const classesList = Array.from(
    new Set(
      videos
        .filter(v => v.visibilite === 'classe' && (v.classe_nom || v.classe_filiere))
        .map(v => v.classe_nom || `${v.classe_niveau || ''} — ${v.classe_filiere || ''}`)
    )
  );

  const filteredVideos = videos.filter(v => {
    if (activeTab === 'publique' && v.visibilite !== 'publique') return false;
    if (activeTab === 'classe' && v.visibilite !== 'classe') return false;

    if (selectedCategorie !== 'all' && v.categorie !== selectedCategorie) return false;

    if (selectedClasse) {
      const cNom = v.classe_nom || `${v.classe_niveau || ''} — ${v.classe_filiere || ''}`;
      if (cNom !== selectedClasse && v.classe_id !== selectedClasse) return false;
    }

    if (searchQuery.trim()) {
      const q = searchQuery.toLowerCase();
      const matchTitre = v.titre?.toLowerCase().includes(q);
      const matchDesc = v.description?.toLowerCase().includes(q);
      const matchPub = v.publie_par_nom?.toLowerCase().includes(q);
      const matchClass = (v.classe_nom || v.classe_filiere || '').toLowerCase().includes(q);
      if (!matchTitre && !matchDesc && !matchPub && !matchClass) return false;
    }

    return true;
  });

  const totalPubliques = videos.filter(v => v.visibilite === 'publique').length;
  const totalClasses = videos.filter(v => v.visibilite === 'classe').length;
  const totalVues = videos.reduce((acc, v) => acc + (v.vues || 0), 0);

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-on-surface flex items-center gap-2">
            <Video className="w-7 h-7 text-primary" />
            Vidéothèque ISGI — Secrétariat
          </h1>
          <p className="text-sm text-on-surface-variant mt-1">
            Supervision de toutes les vidéos de cours — Bibliothèque Publique & Vidéos par Classe
          </p>
        </div>
        <button
          onClick={fetchVideos}
          className="flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-xl border border-outline-variant hover:bg-surface-container transition-colors"
        >
          <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          Actualiser
        </button>
      </div>

      {/* KPI Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-4 gap-4">
        <div className="bg-surface-container rounded-2xl p-4 border border-outline-variant">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-on-surface-variant uppercase">Total Vidéos</span>
            <FolderOpen className="w-4 h-4 text-primary" />
          </div>
          <p className="text-2xl font-bold text-on-surface mt-2">{videos.length}</p>
          <p className="text-xs text-on-surface-variant mt-1">Publiées dans le système</p>
        </div>

        <div className="bg-surface-container rounded-2xl p-4 border border-outline-variant">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-on-surface-variant uppercase">Publiques</span>
            <Globe className="w-4 h-4 text-emerald-500" />
          </div>
          <p className="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-2">{totalPubliques}</p>
          <p className="text-xs text-on-surface-variant mt-1">Visibles par tous</p>
        </div>

        <div className="bg-surface-container rounded-2xl p-4 border border-outline-variant">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-on-surface-variant uppercase">Par Classe</span>
            <GraduationCap className="w-4 h-4 text-blue-500" />
          </div>
          <p className="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-2">{totalClasses}</p>
          <p className="text-xs text-on-surface-variant mt-1">Contrôle d'accès strict</p>
        </div>

        <div className="bg-surface-container rounded-2xl p-4 border border-outline-variant">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-on-surface-variant uppercase">Vues Totales</span>
            <Eye className="w-4 h-4 text-purple-500" />
          </div>
          <p className="text-2xl font-bold text-purple-600 dark:text-purple-400 mt-2">{totalVues}</p>
          <p className="text-xs text-on-surface-variant mt-1">Consultations enregistrées</p>
        </div>
      </div>

      {/* Tabs & Filters */}
      <div className="bg-surface-container rounded-2xl p-4 border border-outline-variant space-y-4">
        {/* Main Tab selector */}
        <div className="flex flex-wrap gap-2 border-b border-outline-variant pb-3">
          <button
            onClick={() => setActiveTab('all')}
            className={`px-4 py-2 rounded-xl text-sm font-semibold transition-all ${
              activeTab === 'all'
                ? 'bg-primary text-on-primary shadow-sm'
                : 'text-on-surface-variant hover:bg-surface-container-highest'
            }`}
          >
            Toutes les Vidéos ({videos.length})
          </button>
          <button
            onClick={() => setActiveTab('publique')}
            className={`flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold transition-all ${
              activeTab === 'publique'
                ? 'bg-emerald-600 text-white shadow-sm'
                : 'text-on-surface-variant hover:bg-surface-container-highest'
            }`}
          >
            <Globe className="w-4 h-4" />
            Bibliothèque Publique ({totalPubliques})
          </button>
          <button
            onClick={() => setActiveTab('classe')}
            className={`flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold transition-all ${
              activeTab === 'classe'
                ? 'bg-blue-600 text-white shadow-sm'
                : 'text-on-surface-variant hover:bg-surface-container-highest'
            }`}
          >
            <GraduationCap className="w-4 h-4" />
            Vidéos par Classe ({totalClasses})
          </button>
        </div>

        {/* Filter Toolbar */}
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
          {/* Search bar */}
          <div className="relative">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant" />
            <input
              type="text"
              placeholder="Rechercher par titre, cours, enseignant..."
              value={searchQuery}
              onChange={e => setSearchQuery(e.target.value)}
              className="w-full pl-9 pr-3 py-2 text-sm rounded-xl bg-surface-container-lowest border border-outline-variant text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/30"
            />
          </div>

          {/* Select Classe */}
          <div className="relative">
            <select
              value={selectedClasse}
              onChange={e => setSelectedClasse(e.target.value)}
              className="w-full px-3 py-2 text-sm rounded-xl bg-surface-container-lowest border border-outline-variant text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/30 font-medium"
            >
              <option value="">🎓 Toutes les classes</option>
              {classesList.map(c => (
                <option key={c} value={c}>{c}</option>
              ))}
            </select>
          </div>

          {/* Select Catégorie */}
          <div className="relative">
            <select
              value={selectedCategorie}
              onChange={e => setSelectedCategorie(e.target.value)}
              className="w-full px-3 py-2 text-sm rounded-xl bg-surface-container-lowest border border-outline-variant text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/30"
            >
              <option value="all">Toutes catégories</option>
              <option value="cours">📚 Cours magistral</option>
              <option value="tutoriel">🎯 Travaux Pratiques / Tutoriel</option>
              <option value="presentation">📊 Présentation / Conférence</option>
              <option value="autre">📁 Autre</option>
            </select>
          </div>
        </div>
      </div>

      {/* Videos Grid */}
      {filteredVideos.length === 0 ? (
        <div className="bg-surface-container rounded-2xl p-12 text-center border border-outline-variant">
          <Video className="w-12 h-12 text-on-surface-variant mx-auto mb-3 opacity-40" />
          <h3 className="text-base font-semibold text-on-surface">Aucune vidéo trouvée</h3>
          <p className="text-sm text-on-surface-variant mt-1 max-w-sm mx-auto">
            {searchQuery || selectedClasse || selectedCategorie !== 'all'
              ? 'Aucun résultat ne correspond à vos critères de recherche.'
              : 'Aucune vidéo n’a encore été publiée dans cette section.'}
          </p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
          {filteredVideos.map(video => (
            <div
              key={video.id}
              onClick={() => setActiveVideo(video)}
              className="group bg-surface-container hover:bg-surface-container-high rounded-2xl border border-outline-variant overflow-hidden cursor-pointer transition-all duration-200 hover:shadow-md flex flex-col"
            >
              {/* Thumbnail / Video Banner */}
              <div className="relative aspect-video bg-black/80 flex items-center justify-center overflow-hidden">
                {video.url_miniature ? (
                  <img
                    src={video.url_miniature}
                    alt={video.titre}
                    className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                  />
                ) : (
                  <div className="text-white/40 flex flex-col items-center">
                    <Video className="w-12 h-12 stroke-[1.2]" />
                  </div>
                )}

                {/* Play Button Overlay */}
                <div className="absolute inset-0 bg-black/30 group-hover:bg-black/20 flex items-center justify-center transition-colors">
                  <div className="w-12 h-12 rounded-full bg-primary text-on-primary flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform">
                    <Play className="w-5 h-5 ml-0.5 fill-current" />
                  </div>
                </div>

                {/* Badge visibilité */}
                <div className="absolute top-3 left-3">
                  {video.visibilite === 'classe' ? (
                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-blue-600/90 text-white backdrop-blur-sm shadow-xs">
                      <GraduationCap className="w-3.5 h-3.5" />
                      Classe
                    </span>
                  ) : (
                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-600/90 text-white backdrop-blur-sm shadow-xs">
                      <Globe className="w-3.5 h-3.5" />
                      Publique
                    </span>
                  )}
                </div>

                {/* Vues badge */}
                {video.vues > 0 && (
                  <div className="absolute bottom-3 right-3 px-2 py-0.5 rounded-md text-[11px] font-medium bg-black/70 text-white backdrop-blur-sm flex items-center gap-1">
                    <Eye className="w-3 h-3" />
                    {video.vues}
                  </div>
                )}
              </div>

              {/* Video Details */}
              <div className="p-4 flex-1 flex flex-col justify-between space-y-3">
                <div>
                  <div className="flex items-center gap-2 mb-1.5">
                    <span className="text-[11px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded bg-primary/10 text-primary">
                      {video.categorie}
                    </span>
                  </div>

                  <h3 className="font-semibold text-base text-on-surface line-clamp-1 group-hover:text-primary transition-colors">
                    {video.titre}
                  </h3>

                  {video.description && (
                    <p className="text-xs text-on-surface-variant line-clamp-2 mt-1">
                      {video.description}
                    </p>
                  )}
                </div>

                {/* Target Class Badge */}
                <div className="pt-2 border-t border-outline-variant/60">
                  {video.visibilite === 'classe' ? (
                    <div className="text-xs font-semibold text-blue-600 dark:text-blue-400 flex items-center gap-1.5 truncate">
                      <GraduationCap className="w-3.5 h-3.5 shrink-0" />
                      <span className="truncate">
                        {video.classe_nom || `${video.classe_niveau || ''} — ${video.classe_filiere || ''}`}
                      </span>
                    </div>
                  ) : (
                    <div className="text-xs font-medium text-emerald-600 dark:text-emerald-400 flex items-center gap-1.5">
                      <Globe className="w-3.5 h-3.5 shrink-0" />
                      <span>Accessible à toute l'école</span>
                    </div>
                  )}

                  <div className="flex items-center justify-between text-[11px] text-on-surface-variant mt-2">
                    <span className="truncate">{video.publie_par_nom || 'Service Informatique'}</span>
                    <span className="flex items-center gap-1 shrink-0">
                      <Calendar className="w-3 h-3" />
                      {new Date(video.created_at).toLocaleDateString('fr-FR')}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Modal Video Player */}
      {activeVideo && (
        <div
          className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4"
          onClick={() => setActiveVideo(null)}
        >
          <div
            className="bg-surface-container rounded-3xl border border-outline-variant max-w-4xl w-full overflow-hidden shadow-2xl animate-in fade-in duration-200"
            onClick={e => e.stopPropagation()}
          >
            {/* Modal Header */}
            <div className="p-4 px-6 border-b border-outline-variant flex items-center justify-between bg-surface-container-high">
              <div className="flex items-center gap-3">
                <div className="w-9 h-9 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                  <Play className="w-5 h-5 fill-current" />
                </div>
                <div>
                  <h3 className="font-bold text-lg text-on-surface">{activeVideo.titre}</h3>
                  <p className="text-xs text-on-surface-variant">
                    {activeVideo.visibilite === 'classe'
                      ? `🎓 ${activeVideo.classe_nom || activeVideo.classe_filiere}`
                      : '🌐 Bibliothèque Publique'}
                  </p>
                </div>
              </div>
              <button
                onClick={() => setActiveVideo(null)}
                className="w-8 h-8 rounded-full flex items-center justify-center text-on-surface-variant hover:bg-surface-container hover:text-on-surface transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Video Player */}
            <div className="relative aspect-video bg-black flex items-center justify-center">
              {activeVideo.url_video ? (
                <video
                  src={activeVideo.url_video}
                  controls
                  autoPlay
                  className="w-full h-full"
                >
                  Votre navigateur ne supporte pas ce format de vidéo.
                </video>
              ) : (
                <div className="text-center text-white/60 p-8">
                  <Video className="w-16 h-16 mx-auto mb-2 opacity-40" />
                  <p className="text-sm font-medium">Vidéo de cours ou fichier hébergé sur le serveur</p>
                </div>
              )}
            </div>

            {/* Modal Footer / Metadata */}
            <div className="p-6 space-y-4">
              {activeVideo.description && (
                <div>
                  <h4 className="text-xs font-bold uppercase tracking-wider text-on-surface-variant mb-1">Description</h4>
                  <p className="text-sm text-on-surface leading-relaxed">{activeVideo.description}</p>
                </div>
              )}

              <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-4 border-t border-outline-variant text-xs">
                <div>
                  <span className="text-on-surface-variant block">Visibilité</span>
                  <span className="font-semibold text-on-surface capitalize">
                    {activeVideo.visibilite === 'classe' ? '🎓 Classe spécifique' : '🌐 Publique'}
                  </span>
                </div>
                <div>
                  <span className="text-on-surface-variant block">Classe Cible</span>
                  <span className="font-semibold text-on-surface">
                    {activeVideo.classe_nom || activeVideo.classe_filiere || 'Toutes'}
                  </span>
                </div>
                <div>
                  <span className="text-on-surface-variant block">Publié par</span>
                  <span className="font-semibold text-on-surface">
                    {activeVideo.publie_par_nom || 'Service Informatique'}
                  </span>
                </div>
                <div>
                  <span className="text-on-surface-variant block">Date de publication</span>
                  <span className="font-semibold text-on-surface">
                    {new Date(activeVideo.created_at).toLocaleDateString('fr-FR')}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
