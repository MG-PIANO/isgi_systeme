import { useState, useMemo, useEffect } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import { db, type Etudiant } from '../../db/db';
import { supabase } from '../../db/supabaseClient';
import { useSettings } from '../../hooks/useSettings';
import {
  getAnnualCost,
  isPaymentTypeInscription,
  isPaymentTypePolo,
  isPaymentTypeCarte,
  isPaymentTypeAssurance,
  isPaymentTypeFournitures,
  isPaymentTypeScolarite
} from '../../utils/pricing';
import { exportToPDF } from '../../utils/pdfExport';
import {
  Target,
  AlertTriangle,
  CheckCircle2,
  Download,
  Search,
  Phone,
  ChevronDown,
  ChevronUp,
  BookOpen,
  GraduationCap,
  TrendingUp,
  Calendar,
  Clock,
  UserX,
  Info
} from 'lucide-react';
import {
  ResponsiveContainer,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend
} from 'recharts';
import { cn } from '../../lib/utils';

const MOIS_ANNEE = [
  { index: 9, nom: 'Octobre', label: 'Octobre 2026' },
  { index: 10, nom: 'Novembre', label: 'Novembre 2026' },
  { index: 11, nom: 'Décembre', label: 'Décembre 2026' },
  { index: 0, nom: 'Janvier', label: 'Janvier 2027' },
  { index: 1, nom: 'Février', label: 'Février 2027' },
  { index: 2, nom: 'Mars', label: 'Mars 2027' },
  { index: 3, nom: 'Avril', label: 'Avril 2027' },
  { index: 4, nom: 'Mai', label: 'Mai 2027' },
  { index: 5, nom: 'Juin', label: 'Juin 2027' },
  { index: 6, nom: 'Juillet', label: 'Juillet 2027' }
];

export function PrevisionsRoiPage() {
  const { settings } = useSettings();
  const [activeTab, setActiveTab] = useState<'roi' | 'previsions_inscrits' | 'prevision_mois' | 'projection_annuelle'>('previsions_inscrits');

  // Sélections
  const [selectedMoisIndex, setSelectedMoisIndex] = useState<number>(new Date().getMonth());
  const [searchDebiteur, setSearchDebiteur] = useState<string>('');
  const [expandedOption, setExpandedOption] = useState<string | null>(null);
  const [filterOptionView, setFilterOptionView] = useState<string>('all');
  const [debiteurTab, setDebiteurTab] = useState<'inscriptions' | 'scolarite' | 'tous'>('inscriptions');

  // Tarifs personnalisés pour les prévisions
  const [customTarifs] = useState({
    prixInscription: settings.tarifs?.inscriptions?.nouvelle || 25000,
    prixPolo: (settings.tarifs?.inscriptions as any)?.polo || 10000,
    prixCarte: settings.tarifs?.inscriptions?.carteEtudiant || 5000,
    prixAssurance: (settings.tarifs?.inscriptions as any)?.assurance || 5000
  });

  // Données Dexie
  const etudiants = useLiveQuery(() => db.etudiants.toArray(), []) || [];
  const paiementsRaw = useLiveQuery(() => db.paiements.toArray(), []) || [];
  const paiements = useMemo(() => {
    return paiementsRaw.filter(p => p.statut === 'Réussi' || !p.statut);
  }, [paiementsRaw]);

  // Données Supabase pour les dépenses et salaires
  const [depensesTotal, setDepensesTotal] = useState<number>(0);
  const [salairesTotal, setSalairesTotal] = useState<number>(0);

  useEffect(() => {
    const fetchDepensesAndSalaires = async () => {
      try {
        const [depRes, paieRes] = await Promise.all([
          supabase.from('depenses').select('montant'),
          supabase.from('paies').select('montant_net')
        ]);
        const depSum = (depRes.data || []).reduce((acc: number, cur: any) => acc + (Number(cur.montant) || 0), 0);
        const paieSum = (paieRes.data || []).reduce((acc: number, cur: any) => acc + (Number(cur.montant_net) || 0), 0);
        setDepensesTotal(depSum);
        setSalairesTotal(paieSum);
      } catch (err) {
        console.warn('Erreur chargement dépenses:', err);
      }
    };
    fetchDepensesAndSalaires();
  }, []);

  const totalCharges = depensesTotal + salairesTotal;
  const nbEtudiants = etudiants.length;

  // -------------------------------------------------------------
  // 1. CALCULS DES RECETTES GLOBALES (ENCAISSÉES VS ATTENDUES)
  // -------------------------------------------------------------
  const statsRecettes = useMemo(() => {
    const encaisseInscriptions = paiements
      .filter(p => isPaymentTypeInscription(p.type_paiement))
      .reduce((sum, p) => sum + (Number(p.montant) || 0), 0);

    const encaissePolos = paiements
      .filter(p => isPaymentTypePolo(p.type_paiement))
      .reduce((sum, p) => sum + (Number(p.montant) || 0), 0);

    const encaisseCartes = paiements
      .filter(p => isPaymentTypeCarte(p.type_paiement))
      .reduce((sum, p) => sum + (Number(p.montant) || 0), 0);

    const encaisseAssurance = paiements
      .filter(p => isPaymentTypeAssurance(p.type_paiement))
      .reduce((sum, p) => sum + (Number(p.montant) || 0), 0);

    const encaisseFournitures = paiements
      .filter(p => isPaymentTypeFournitures(p.type_paiement))
      .reduce((sum, p) => sum + (Number(p.montant) || 0), 0);

    const encaisseScolarite = paiements
      .filter(p => isPaymentTypeScolarite(p.type_paiement))
      .reduce((sum, p) => sum + (Number(p.montant) || 0), 0);

    const totalEncaisse = paiements.reduce((sum, p) => sum + (Number(p.montant) || 0), 0);

    // Recettes théoriques attendues selon le nombre d'inscrits
    const attenduInscriptions = nbEtudiants * customTarifs.prixInscription;
    const attenduPolos = nbEtudiants * customTarifs.prixPolo;
    const attenduCartes = nbEtudiants * customTarifs.prixCarte;
    const attenduAssurance = nbEtudiants * customTarifs.prixAssurance;

    // Scolarité théorique calculée individuellement pour chaque inscrit
    let attenduScolariteAnnuelle = 0;
    etudiants.forEach(etudiant => {
      attenduScolariteAnnuelle += getAnnualCost(etudiant);
    });

    const totalAttenduTheorique =
      attenduInscriptions + attenduPolos + attenduCartes + attenduAssurance + attenduScolariteAnnuelle;

    return {
      encaisseInscriptions,
      encaissePolos,
      encaisseCartes,
      encaisseAssurance,
      encaisseFournitures,
      encaisseScolarite,
      totalEncaisse,
      attenduInscriptions,
      attenduPolos,
      attenduCartes,
      attenduAssurance,
      attenduScolariteAnnuelle,
      totalAttenduTheorique,
      resteARecouvrer: Math.max(0, totalAttenduTheorique - totalEncaisse),
      tauxRecouvrementGlobal: totalAttenduTheorique > 0 ? (totalEncaisse / totalAttenduTheorique) * 100 : 0
    };
  }, [etudiants, paiements, customTarifs, nbEtudiants]);

  // -------------------------------------------------------------
  // 2. PRÉVISIONS DÉTAILLÉES DES FRAIS SCOLAIRES PAR OPTION / FILIÈRE
  // -------------------------------------------------------------
  const statsOptionsScolarite = useMemo(() => {
    const optionsMap: Record<string, {
      optionKey: string;
      label: string;
      badgeColor: string;
      headerBg: string;
      effectif: number;
      totalAttendu: number;
      dejaEncaisse: number;
      resteARecouvrer: number;
      filieresMap: Record<string, { effectif: number; attendu: number; encaisse: number }>;
      niveauxMap: Record<string, { effectif: number; attendu: number; encaisse: number }>;
    }> = {
      'gestion': {
        optionKey: 'gestion',
        label: 'Option Gestion & Administration',
        badgeColor: 'bg-blue-100 text-blue-800 border-blue-200',
        headerBg: 'bg-blue-50 border-blue-200',
        effectif: 0,
        totalAttendu: 0,
        dejaEncaisse: 0,
        resteARecouvrer: 0,
        filieresMap: {},
        niveauxMap: {}
      },
      'industrie': {
        optionKey: 'industrie',
        label: 'Option Industrie & Technologies',
        badgeColor: 'bg-amber-100 text-amber-800 border-amber-200',
        headerBg: 'bg-amber-50 border-amber-200',
        effectif: 0,
        totalAttendu: 0,
        dejaEncaisse: 0,
        resteARecouvrer: 0,
        filieresMap: {},
        niveauxMap: {}
      },
      'droit': {
        optionKey: 'droit',
        label: 'Option Droit & Sciences Juridiques',
        badgeColor: 'bg-purple-100 text-purple-800 border-purple-200',
        headerBg: 'bg-purple-50 border-purple-200',
        effectif: 0,
        totalAttendu: 0,
        dejaEncaisse: 0,
        resteARecouvrer: 0,
        filieresMap: {},
        niveauxMap: {}
      },
      'soir': {
        optionKey: 'soir',
        label: 'Cours du Soir (Tous cycles)',
        badgeColor: 'bg-indigo-100 text-indigo-800 border-indigo-200',
        headerBg: 'bg-indigo-50 border-indigo-200',
        effectif: 0,
        totalAttendu: 0,
        dejaEncaisse: 0,
        resteARecouvrer: 0,
        filieresMap: {},
        niveauxMap: {}
      },
      'international': {
        optionKey: 'international',
        label: 'Cycle International (France / Londres)',
        badgeColor: 'bg-emerald-100 text-emerald-800 border-emerald-200',
        headerBg: 'bg-emerald-50 border-emerald-200',
        effectif: 0,
        totalAttendu: 0,
        dejaEncaisse: 0,
        resteARecouvrer: 0,
        filieresMap: {},
        niveauxMap: {}
      }
    };

    // Paiements de scolarité groupés par etudiant_id ou matricule
    const paiementsScolariteMap = new Map<string, number>();
    paiements.forEach(p => {
      const isScolarite = isPaymentTypeScolarite(p.type_paiement);
      if (isScolarite && p.etudiant_id) {
        const id = p.etudiant_id.trim();
        paiementsScolariteMap.set(id, (paiementsScolariteMap.get(id) || 0) + Number(p.montant || 0));
      }
    });

    etudiants.forEach(etudiant => {
      const optStr = (etudiant.option || '').toLowerCase();
      const filStr = (etudiant.filiere || 'Filière Générale').toUpperCase().trim();
      const cycleStr = (etudiant.cycle_formation || '').toLowerCase();
      const vagueStr = (etudiant.vague || (etudiant as any).session_cours || '').toLowerCase();
      const nivStr = (etudiant.niveau || 'Licence 1').trim();

      let targetKey = 'gestion';
      if (vagueStr.includes('soir')) {
        targetKey = 'soir';
      } else if (cycleStr.includes('france') || cycleStr.includes('londre')) {
        targetKey = 'international';
      } else if (optStr.includes('industrie') || filStr.includes('MÉCANIQUE') || filStr.includes('PÉTROLE') || filStr.includes('GAZ') || filStr.includes('ÉLECTR')) {
        targetKey = 'industrie';
      } else if (optStr.includes('droit') || filStr.includes('DROIT')) {
        targetKey = 'droit';
      }

      const target = optionsMap[targetKey];
      target.effectif += 1;

      // Calcul coût annuel de scolarité
      const coutAnnuel = getAnnualCost(etudiant);
      target.totalAttendu += coutAnnuel;

      // Paiements réels enregistrés pour cet étudiant
      const paye = (paiementsScolariteMap.get(etudiant.matricule) || 0) + 
                   (etudiant.id ? (paiementsScolariteMap.get(etudiant.id) || 0) : 0);
      target.dejaEncaisse += paye;

      // Ventilation par filière spécifique
      if (!target.filieresMap[filStr]) {
        target.filieresMap[filStr] = { effectif: 0, attendu: 0, encaisse: 0 };
      }
      target.filieresMap[filStr].effectif += 1;
      target.filieresMap[filStr].attendu += coutAnnuel;
      target.filieresMap[filStr].encaisse += paye;

      // Ventilation par niveau
      if (!target.niveauxMap[nivStr]) {
        target.niveauxMap[nivStr] = { effectif: 0, attendu: 0, encaisse: 0 };
      }
      target.niveauxMap[nivStr].effectif += 1;
      target.niveauxMap[nivStr].attendu += coutAnnuel;
      target.niveauxMap[nivStr].encaisse += paye;
    });

    return Object.values(optionsMap)
      .map(item => {
        const reste = Math.max(0, item.totalAttendu - item.dejaEncaisse);
        const taux = item.totalAttendu > 0 ? (item.dejaEncaisse / item.totalAttendu) * 100 : 0;
        const montantMoyen = item.effectif > 0 ? Math.round(item.totalAttendu / item.effectif) : 0;
        return {
          ...item,
          resteARecouvrer: reste,
          tauxRecouvrement: taux,
          montantMoyen
        };
      })
      .filter(item => item.effectif > 0);
  }, [etudiants, paiements]);

  // Données de graphique comparatif pour les options
  const chartDataOptions = useMemo(() => {
    return statsOptionsScolarite.map(o => ({
      name: o.label.replace('Option ', '').replace(' (Tous cycles)', '').replace(' (France / Londres)', ''),
      "Scolarité Attendue": Math.round(o.totalAttendu / 1000), // en K FCFA
      "Déjà Encaissé": Math.round(o.dejaEncaisse / 1000),
      "Reste à Recouvrer": Math.round(o.resteARecouvrer / 1000)
    }));
  }, [statsOptionsScolarite]);

  // -------------------------------------------------------------
  // 3. CALCUL DU RETOUR SUR INVESTISSEMENT (ROI) & POINT MORT
  // -------------------------------------------------------------
  const statsRoi = useMemo(() => {
    const recettesReelles = statsRecettes.totalEncaisse;
    const recettesPotentielles = statsRecettes.totalAttenduTheorique;
    const charges = totalCharges > 0 ? totalCharges : 1;

    const beneficeNetReel = recettesReelles - totalCharges;
    const beneficeNetPotentiel = recettesPotentielles - totalCharges;

    const roiReelPourcent = ((beneficeNetReel) / charges) * 100;
    const roiPotentielPourcent = ((beneficeNetPotentiel) / charges) * 100;

    const revenuMoyenParEtudiant = nbEtudiants > 0 ? (recettesPotentielles / nbEtudiants) : 350000;

    const etudiantsRequisPourSeuil = revenuMoyenParEtudiant > 0
      ? Math.ceil(totalCharges / revenuMoyenParEtudiant)
      : 0;

    const ecartSeuilEtudiants = nbEtudiants - etudiantsRequisPourSeuil;
    const estRentable = beneficeNetReel > 0;

    return {
      recettesReelles,
      recettesPotentielles,
      beneficeNetReel,
      beneficeNetPotentiel,
      roiReelPourcent,
      roiPotentielPourcent,
      revenuMoyenParEtudiant,
      etudiantsRequisPourSeuil,
      ecartSeuilEtudiants,
      estRentable
    };
  }, [statsRecettes, totalCharges, nbEtudiants]);

  // -------------------------------------------------------------
  // 4. PRÉVISION DU MOIS & IDENTIFICATION DES IMPAYÉS / DETTES
  // -------------------------------------------------------------
  const statsMois = useMemo(() => {
    const moisSelectionne = MOIS_ANNEE.find(m => m.index === selectedMoisIndex) || MOIS_ANNEE[0];
    const currentDate = new Date();

    // 1. Détermination de la date limite de grâce pour ce mois de l'année scolaire 2026-2027
    const yearOfMois = moisSelectionne.index >= 9 ? 2026 : 2027;
    const nextMonthIndex = (moisSelectionne.index + 1) % 12;
    const yearOfDeadline = (moisSelectionne.index === 11) ? yearOfMois + 1 : (moisSelectionne.index >= 9 ? 2026 : 2027);
    const deadlineDate = new Date(yearOfDeadline, nextMonthIndex, 5, 23, 59, 59);
    const isEcheanceDepassee = currentDate > deadlineDate;

    // Nom du mois de grâce suivant pour affichage (ex: 5 Novembre pour Octobre)
    const nextMoisObj = MOIS_ANNEE.find(m => m.index === nextMonthIndex);
    const nextMoisNom = nextMoisObj ? nextMoisObj.nom : 'Mois suivant';

    // 2. Paiements effectués au cours du mois sélectionné
    const paiementsDuMois = paiements.filter(p => {
      const d = new Date(p.created_at || p.last_modified_at || Date.now());
      return d.getMonth() === selectedMoisIndex;
    });

    // Ventilation des encaissements du mois
    const encaisseInscriptionsMois = paiementsDuMois
      .filter(p => isPaymentTypeInscription(p.type_paiement))
      .reduce((sum, p) => sum + (Number(p.montant) || 0), 0);

    const encaisseAccessoiresMois = paiementsDuMois
      .filter(p => isPaymentTypePolo(p.type_paiement) || isPaymentTypeCarte(p.type_paiement) || isPaymentTypeAssurance(p.type_paiement) || isPaymentTypeFournitures(p.type_paiement))
      .reduce((sum, p) => sum + (Number(p.montant) || 0), 0);

    const encaisseTotalFormalitesMois = encaisseInscriptionsMois + encaisseAccessoiresMois;

    const encaisseScolariteMois = paiementsDuMois
      .filter(p => isPaymentTypeScolarite(p.type_paiement))
      .reduce((sum, p) => sum + (Number(p.montant) || 0), 0);

    const encaisseTotalMois = paiementsDuMois.reduce((acc, p) => acc + (Number(p.montant) || 0), 0);

    // 3. Scolarité mensuelle théorique attendue pour le mois (sur tous les inscrits)
    let objectifMensuelScolarite = 0;
    etudiants.forEach(etudiant => {
      const annual = getAnnualCost(etudiant);
      objectifMensuelScolarite += (annual / 10);
    });

    // 4. Inscriptions du mois (cohorte des inscrits de ce mois)
    const etudiantsInscritsCeMois = etudiants.filter(e => {
      if (!e.created_at) return true;
      const d = new Date(e.created_at);
      return d.getMonth() === selectedMoisIndex;
    });

    const nbInscritsMois = etudiantsInscritsCeMois.length > 0 ? etudiantsInscritsCeMois.length : etudiants.length;
    const objectifInscriptionsMois = nbInscritsMois * customTarifs.prixInscription;

    // 5. Cartographie des paiements d'inscription par étudiant
    const paiementsInscParEtudiant = new Map<string, number>();
    paiements.forEach(p => {
      if (isPaymentTypeInscription(p.type_paiement) && p.etudiant_id) {
        const id = p.etudiant_id.trim();
        paiementsInscParEtudiant.set(id, (paiementsInscParEtudiant.get(id) || 0) + Number(p.montant || 0));
      }
    });

    // Cartographie des paiements de scolarité par étudiant
    const paiementsScolariteParEtudiant = new Map<string, number>();
    paiements.forEach(p => {
      if (isPaymentTypeScolarite(p.type_paiement) && p.etudiant_id) {
        const id = p.etudiant_id.trim();
        paiementsScolariteParEtudiant.set(id, (paiementsScolariteParEtudiant.get(id) || 0) + Number(p.montant || 0));
      }
    });

    // 6. DÉBITEURS D'INSCRIPTION : Étudiants inscrits dont la secrétaire n'a pas encore lié de paiement d'inscription
    const debiteursInscription: Array<{
      etudiant: Etudiant;
      fraisDus: number;
      dejaPaye: number;
      resteDu: number;
      dateInscription: string;
    }> = [];

    let countInscriptionsPayees = 0;

    etudiants.forEach(etudiant => {
      const paye = (paiementsInscParEtudiant.get(etudiant.matricule) || 0) +
                   (etudiant.id ? (paiementsInscParEtudiant.get(etudiant.id) || 0) : 0);
      const fraisDus = customTarifs.prixInscription;
      const reste = Math.max(0, fraisDus - paye);

      if (reste > 0) {
        debiteursInscription.push({
          etudiant,
          fraisDus,
          dejaPaye: paye,
          resteDu: reste,
          dateInscription: etudiant.created_at ? new Date(etudiant.created_at).toLocaleDateString('fr-FR') : 'Octobre 2026'
        });
      } else {
        countInscriptionsPayees += 1;
      }
    });

    // 7. DÉBITEURS DE SCOLARITÉ : Selon échéance du 5 du mois suivant
    const debiteursScolarite: Array<{
      etudiant: Etudiant;
      mensualiteAttendue: number;
      totalScolaritePayee: number;
      montantAttenduCumule: number;
      arrieres: number;
    }> = [];

    // Si et seulement si la date limite de grâce du 5 est passée (ex: après le 5 Novembre pour Octobre)
    if (isEcheanceDepassee) {
      etudiants.forEach(etudiant => {
        const annual = getAnnualCost(etudiant);
        const mens = annual / 10;
        const paye = (paiementsScolariteParEtudiant.get(etudiant.matricule) || 0) +
                     (etudiant.id ? (paiementsScolariteParEtudiant.get(etudiant.id) || 0) : 0);

        const expectedMonths = Math.max(1, (moisSelectionne.index === 9 ? 1 : (moisSelectionne.index >= 9 ? moisSelectionne.index - 8 : moisSelectionne.index + 4)));
        const attenduCumul = mens * expectedMonths;
        const arrieres = Math.max(0, attenduCumul - paye);

        if (arrieres > 0) {
          debiteursScolarite.push({
            etudiant,
            mensualiteAttendue: mens,
            totalScolaritePayee: paye,
            montantAttenduCumule: attenduCumul,
            arrieres
          });
        }
      });
      debiteursScolarite.sort((a, b) => b.arrieres - a.arrieres);
    }

    return {
      moisNom: moisSelectionne.nom,
      moisLabel: moisSelectionne.label,
      isEcheanceDepassee,
      deadlineDate,
      nextMoisNom,
      
      // Inscriptions
      nbInscritsMois,
      objectifInscriptionsMois,
      encaisseInscriptionsMois,
      encaisseAccessoiresMois,
      encaisseTotalFormalitesMois,
      countInscriptionsPayees,
      debiteursInscription,
      totalDetteInscription: debiteursInscription.reduce((acc, d) => acc + d.resteDu, 0),

      // Scolarité
      objectifMensuelScolarite,
      encaisseScolariteMois,
      debiteursScolarite,
      totalDetteScolarite: debiteursScolarite.reduce((acc, d) => acc + d.arrieres, 0),

      // Global mois
      encaisseTotalMois
    };
  }, [etudiants, paiements, selectedMoisIndex, customTarifs]);

  // Export PDF Spécifique : Débiteurs selon onglet sélectionné
  const handleExportDebiteursPDF = () => {
    if (debiteurTab === 'inscriptions') {
      const columns = [
        { header: 'Matricule', dataKey: 'matricule' },
        { header: 'Nom & Prénom', dataKey: 'nom' },
        { header: 'Filière & Niveau', dataKey: 'filiere' },
        { header: 'Contact', dataKey: 'contact' },
        { header: 'Frais Dus', dataKey: 'dus' },
        { header: 'Déjà Versé', dataKey: 'paye' },
        { header: 'Reste à Régulariser', dataKey: 'reste' }
      ];

      const data = statsMois.debiteursInscription.map(d => ({
        matricule: d.etudiant.matricule,
        nom: `${d.etudiant.nom} ${d.etudiant.prenom}`,
        filiere: `${d.etudiant.filiere} (${d.etudiant.niveau})`,
        contact: d.etudiant.telephone || 'N/A',
        dus: `${d.fraisDus.toLocaleString('fr-FR')} F`,
        paye: `${d.dejaPaye.toLocaleString('fr-FR')} F`,
        reste: `${d.resteDu.toLocaleString('fr-FR')} FCFA`
      }));

      exportToPDF(
        `ISGI - Inscriptions Non Soldées (${statsMois.debiteursInscription.length} Étudiants sans Paiement Lié)`,
        columns,
        data,
        `isgi_inscriptions_non_soldees_${statsMois.moisNom.toLowerCase()}`
      );
    } else {
      const columns = [
        { header: 'Matricule', dataKey: 'matricule' },
        { header: 'Nom & Prénom', dataKey: 'nom' },
        { header: 'Filière & Niveau', dataKey: 'filiere' },
        { header: 'Contact', dataKey: 'contact' },
        { header: 'Mensualité', dataKey: 'mensualite' },
        { header: 'Déjà Versé', dataKey: 'paye' },
        { header: 'Arriéré Dû', dataKey: 'arrieres' }
      ];

      const data = statsMois.debiteursScolarite.map(d => ({
        matricule: d.etudiant.matricule,
        nom: `${d.etudiant.nom} ${d.etudiant.prenom}`,
        filiere: `${d.etudiant.filiere} (${d.etudiant.niveau})`,
        contact: d.etudiant.telephone || 'N/A',
        mensualite: `${Math.round(d.mensualiteAttendue).toLocaleString('fr-FR')} F`,
        paye: `${Math.round(d.totalScolaritePayee).toLocaleString('fr-FR')} F`,
        arrieres: `${Math.round(d.arrieres).toLocaleString('fr-FR')} FCFA`
      }));

      exportToPDF(
        `ISGI - Retards de Scolarité pour ${statsMois.moisLabel}`,
        columns,
        data,
        `isgi_debiteurs_scolarite_${statsMois.moisNom.toLowerCase()}`
      );
    }
  };

  // Export PDF Spécifique : Prévisions Scolarité par Option
  const handleExportOptionsPDF = () => {
    const columns = [
      { header: 'Option de Formation', dataKey: 'option' },
      { header: 'Inscrits', dataKey: 'effectif' },
      { header: 'Tarif Moyen', dataKey: 'moyen' },
      { header: 'Scolarité Attendue', dataKey: 'attendu' },
      { header: 'Déjà Encaissé', dataKey: 'encaisse' },
      { header: 'Reste à Recouvrer', dataKey: 'reste' },
      { header: 'Taux Réalisé', dataKey: 'taux' }
    ];

    const data = statsOptionsScolarite.map(o => ({
      option: o.label,
      effectif: `${o.effectif} étudiants`,
      moyen: `${o.montantMoyen.toLocaleString('fr-FR')} F`,
      attendu: `${Math.round(o.totalAttendu).toLocaleString('fr-FR')} F`,
      encaisse: `${Math.round(o.dejaEncaisse).toLocaleString('fr-FR')} F`,
      reste: `${Math.round(o.resteARecouvrer).toLocaleString('fr-FR')} F`,
      taux: `${o.tauxRecouvrement.toFixed(1)}%`
    }));

    exportToPDF(
      'ISGI - Prévisions des Frais Scolaires par Option',
      columns,
      data,
      'isgi_previsions_scolarite_options'
    );
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm space-y-4">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-50 text-blue-800 text-xs font-bold border border-blue-200 mb-2">
              <TrendingUp className="w-3.5 h-3.5" />
              Pilotage Budgétaire & Stratégique
            </div>
            <h2 className="text-2xl font-black text-slate-900 tracking-tight">
              Prévisions des Frais Scolaires & ROI
            </h2>
            <p className="text-xs text-slate-500 font-medium">
              Simulation et analyse prévisionnelle des inscriptions et des frais de scolarité par option
            </p>
          </div>

          <div className="flex items-center gap-2">
            <button
              onClick={handleExportOptionsPDF}
              className="flex items-center gap-2 px-4 py-2 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-bold shadow-xs transition-colors"
            >
              <Download className="w-4 h-4 text-emerald-400" />
              Exporter Prévisions Scolarité (PDF)
            </button>
          </div>
        </div>

        {/* Navigation des 4 onglets */}
        <div className="flex flex-wrap gap-2 pt-2 border-t border-slate-100">
          {[
            { id: 'previsions_inscrits', label: "🎯 Prévisions par Inscrits & Options", desc: "Recettes & Scolarité par option" },
            { id: 'prevision_mois', label: "📅 Prévision du Mois & Dettes", desc: "Suivi mensuel et retardataires" },
            { id: 'roi', label: "📈 ROI & Rentabilité", desc: "Seuil de rentabilité et marges" },
            { id: 'projection_annuelle', label: "📊 Projection Annuelle", desc: "Calendrier sur 10 mois" }
          ].map(tab => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id as any)}
              className={cn(
                "px-4 py-2.5 rounded-2xl text-xs font-bold transition-all flex items-center gap-2",
                activeTab === tab.id
                  ? "bg-slate-900 text-white shadow-md shadow-slate-900/20"
                  : "bg-slate-50 text-slate-600 hover:bg-slate-100 border border-slate-200/60"
              )}
            >
              <span>{tab.label}</span>
            </button>
          ))}
        </div>
      </div>

      {/* ========================================================================= */}
      {/* ONGLET 1 : PRÉVISIONS PAR NOMBRE D'INSCRITS & PAR OPTIONS                  */}
      {/* ========================================================================= */}
      {activeTab === 'previsions_inscrits' && (
        <div className="space-y-6">
          {/* Cartes KPI Synthèse Générale */}
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div className="bg-gradient-to-br from-blue-900 to-indigo-950 text-white rounded-3xl p-5 shadow-sm space-y-2">
              <div className="flex items-center justify-between text-blue-200 text-xs font-bold">
                <span>POTENTIEL THÉORIQUE TOTAL</span>
                <Target className="w-5 h-5 text-blue-300" />
              </div>
              <div className="text-2xl font-black tracking-tight">
                {Math.round(statsRecettes.totalAttenduTheorique).toLocaleString('fr-FR')} FCFA
              </div>
              <p className="text-[11px] text-blue-200/80">
                Inscriptions ({Math.round(statsRecettes.attenduInscriptions).toLocaleString('fr-FR')} F) + Scolarité ({Math.round(statsRecettes.attenduScolariteAnnuelle).toLocaleString('fr-FR')} F)
              </p>
            </div>

            <div className="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm space-y-2">
              <div className="flex items-center justify-between text-slate-500 text-xs font-bold">
                <span>TOTAL DÉJÀ ENCAISSÉ</span>
                <CheckCircle2 className="w-5 h-5 text-emerald-500" />
              </div>
              <div className="text-2xl font-black text-slate-900">
                {Math.round(statsRecettes.totalEncaisse).toLocaleString('fr-FR')} FCFA
              </div>
              <div className="flex items-center gap-2">
                <div className="flex-1 bg-slate-100 rounded-full h-2 overflow-hidden">
                  <div
                    className="bg-emerald-500 h-full rounded-full transition-all"
                    style={{ width: `${Math.min(100, statsRecettes.tauxRecouvrementGlobal)}%` }}
                  />
                </div>
                <span className="text-xs font-black text-emerald-600">
                  {statsRecettes.tauxRecouvrementGlobal.toFixed(1)}%
                </span>
              </div>
            </div>

            <div className="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm space-y-2">
              <div className="flex items-center justify-between text-slate-500 text-xs font-bold">
                <span>RESTE À RECOUVRER (TOTAL)</span>
                <AlertTriangle className="w-5 h-5 text-amber-500" />
              </div>
              <div className="text-2xl font-black text-amber-600">
                {Math.round(statsRecettes.resteARecouvrer).toLocaleString('fr-FR')} FCFA
              </div>
              <p className="text-[11px] text-slate-400">
                Frais d'inscription et scolarités restant à percevoir
              </p>
            </div>

            <div className="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm space-y-2">
              <div className="flex items-center justify-between text-slate-500 text-xs font-bold">
                <span>SCOLARITÉ ATTENDUE SEULE</span>
                <GraduationCap className="w-5 h-5 text-indigo-500" />
              </div>
              <div className="text-2xl font-black text-indigo-700">
                {Math.round(statsRecettes.attenduScolariteAnnuelle).toLocaleString('fr-FR')} FCFA
              </div>
              <p className="text-[11px] text-slate-400">
                Prévision nette des frais de cours des {nbEtudiants} inscrits
              </p>
            </div>
          </div>

          {/* ========================================================================= */}
          {/* NOUVEAU BLOC MAJEUR : PRÉVISIONS DES FRAIS SCOLAIRES PAR OPTION           */}
          {/* ========================================================================= */}
          <div className="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 space-y-6">
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-100 pb-4">
              <div>
                <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-50 text-emerald-800 text-xs font-bold border border-emerald-200 mb-1">
                  <BookOpen className="w-3.5 h-3.5" />
                  Ventilation par Options & Filières
                </div>
                <h3 className="text-lg font-black text-slate-900 tracking-tight">
                  Prévisions Détaillées des Frais Scolaires par Option
                </h3>
                <p className="text-xs text-slate-500">
                  Calcul de la scolarité attendue en fonction de la grille tarifaire spécifique de chaque option
                </p>
              </div>

              <div className="flex items-center gap-2">
                <span className="text-xs font-bold text-slate-500">Filtrer par option :</span>
                <select
                  value={filterOptionView}
                  onChange={(e) => setFilterOptionView(e.target.value)}
                  className="px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold text-slate-800"
                >
                  <option value="all">Toutes les options ({statsOptionsScolarite.length})</option>
                  {statsOptionsScolarite.map(o => (
                    <option key={o.optionKey} value={o.optionKey}>{o.label}</option>
                  ))}
                </select>
              </div>
            </div>

            {/* Cartes des Options */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              {statsOptionsScolarite
                .filter(o => filterOptionView === 'all' || o.optionKey === filterOptionView)
                .map((opt) => (
                  <div
                    key={opt.optionKey}
                    className="p-5 rounded-3xl border border-slate-200 bg-slate-50/60 shadow-2xs space-y-3 hover:border-blue-400 transition-all"
                  >
                    <div className="flex items-center justify-between">
                      <span className={cn("px-2.5 py-1 text-[11px] font-black rounded-full border", opt.badgeColor)}>
                        {opt.label}
                      </span>
                      <span className="text-xs font-bold text-slate-600 bg-white px-2.5 py-1 rounded-full border border-slate-200">
                        {opt.effectif} étudiant(s)
                      </span>
                    </div>

                    <div className="space-y-1">
                      <div className="text-xs text-slate-500 font-semibold">Scolarité Totale Attendue :</div>
                      <div className="text-xl font-black text-slate-900">
                        {Math.round(opt.totalAttendu).toLocaleString('fr-FR')} FCFA
                      </div>
                      <div className="text-[11px] text-slate-400 font-medium">
                        Moyenne unitaire : ~{opt.montantMoyen.toLocaleString('fr-FR')} FCFA / étudiant
                      </div>
                    </div>

                    <div className="pt-2 border-t border-slate-200/80 space-y-1.5 text-xs">
                      <div className="flex justify-between items-center">
                        <span className="text-slate-500">Déjà Encaissé :</span>
                        <span className="font-bold text-emerald-600">{Math.round(opt.dejaEncaisse).toLocaleString('fr-FR')} FCFA</span>
                      </div>
                      <div className="flex justify-between items-center">
                        <span className="text-slate-500">Reste à Recouvrer :</span>
                        <span className="font-bold text-amber-600">{Math.round(opt.resteARecouvrer).toLocaleString('fr-FR')} FCFA</span>
                      </div>

                      <div className="space-y-1 pt-1">
                        <div className="flex justify-between text-[10px] font-bold text-slate-600">
                          <span>Taux Réalisé :</span>
                          <span>{opt.tauxRecouvrement.toFixed(1)}%</span>
                        </div>
                        <div className="w-full h-2 rounded-full bg-slate-200 overflow-hidden">
                          <div
                            className="h-full rounded-full bg-blue-600 transition-all duration-500"
                            style={{ width: `${Math.min(100, opt.tauxRecouvrement)}%` }}
                          />
                        </div>
                      </div>
                    </div>
                  </div>
                ))}
            </div>

            {/* Graphique BarChart Comparatif par Option */}
            <div className="bg-slate-50/80 p-5 rounded-3xl border border-slate-200 space-y-3">
              <div className="flex items-center justify-between">
                <div>
                  <h4 className="text-sm font-black text-slate-900 uppercase tracking-wider">
                    Comparatif Visuel des Frais Scolaires par Option (en milliers FCFA)
                  </h4>
                  <p className="text-[11px] text-slate-500">Montant attendu vs déjà encaissé vs solde restant</p>
                </div>
              </div>

              <div className="h-64 w-full">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={chartDataOptions}>
                    <CartesianGrid strokeDasharray="3 3" opacity={0.2} />
                    <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                    <YAxis tick={{ fontSize: 11 }} />
                    <Tooltip formatter={(value: any) => `${(Number(value) * 1000).toLocaleString('fr-FR')} FCFA`} />
                    <Legend wrapperStyle={{ fontSize: 12 }} />
                    <Bar dataKey="Scolarité Attendue" fill="#1e3a8a" radius={[6, 6, 0, 0]} />
                    <Bar dataKey="Déjà Encaissé" fill="#10b981" radius={[6, 6, 0, 0]} />
                    <Bar dataKey="Reste à Recouvrer" fill="#f59e0b" radius={[6, 6, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </div>

            {/* Tableau Détaillé des Options avec Accordéon des Filières */}
            <div className="rounded-2xl border border-slate-200 overflow-hidden">
              <div className="p-4 bg-slate-100/70 border-b border-slate-200 flex items-center justify-between">
                <span className="text-xs font-black text-slate-800 uppercase tracking-wider">
                  Tableau Analytique de la Scolarité par Option & Filières Internes
                </span>
                <span className="text-xs text-slate-500">Cliquez sur une option pour déplier ses filières</span>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full border-collapse text-left text-xs">
                  <thead>
                    <tr className="bg-slate-100 border-b border-slate-200 font-bold text-slate-700 uppercase text-[11px]">
                      <th className="py-3 px-4">Option de Formation</th>
                      <th className="py-3 px-4 text-center">Effectif</th>
                      <th className="py-3 px-4 text-right">Tarif Moyen</th>
                      <th className="py-3 px-4 text-right">Scolarité Attendue</th>
                      <th className="py-3 px-4 text-right">Déjà Encaissé</th>
                      <th className="py-3 px-4 text-right">Reste à Recouvrer</th>
                      <th className="py-3 px-4 text-center">Taux</th>
                      <th className="py-3 px-4 text-center">Action</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {statsOptionsScolarite
                      .filter(o => filterOptionView === 'all' || o.optionKey === filterOptionView)
                      .map((opt) => {
                        const isExpanded = expandedOption === opt.optionKey;
                        const filieresList = Object.entries(opt.filieresMap);

                        return (
                          <>
                            <tr
                              key={opt.optionKey}
                              onClick={() => setExpandedOption(isExpanded ? null : opt.optionKey)}
                              className="hover:bg-blue-50/40 transition-colors cursor-pointer bg-white"
                            >
                              <td className="py-3.5 px-4 font-black text-slate-900">
                                <div className="flex items-center gap-2">
                                  {isExpanded ? <ChevronUp className="w-4 h-4 text-blue-600 shrink-0" /> : <ChevronDown className="w-4 h-4 text-slate-400 shrink-0" />}
                                  <span>{opt.label}</span>
                                </div>
                              </td>
                              <td className="py-3.5 px-4 text-center font-bold text-slate-800">
                                {opt.effectif}
                              </td>
                              <td className="py-3.5 px-4 text-right font-medium text-slate-600">
                                {opt.montantMoyen.toLocaleString('fr-FR')} F
                              </td>
                              <td className="py-3.5 px-4 text-right font-black text-slate-900">
                                {Math.round(opt.totalAttendu).toLocaleString('fr-FR')} FCFA
                              </td>
                              <td className="py-3.5 px-4 text-right font-bold text-emerald-600">
                                {Math.round(opt.dejaEncaisse).toLocaleString('fr-FR')} FCFA
                              </td>
                              <td className="py-3.5 px-4 text-right font-bold text-amber-600">
                                {Math.round(opt.resteARecouvrer).toLocaleString('fr-FR')} FCFA
                              </td>
                              <td className="py-3.5 px-4 text-center">
                                <span className="px-2 py-0.5 rounded-full text-[10px] font-black bg-blue-100 text-blue-800">
                                  {opt.tauxRecouvrement.toFixed(1)}%
                                </span>
                              </td>
                              <td className="py-3.5 px-4 text-center">
                                <span className="text-[11px] font-bold text-blue-600 hover:underline">
                                  {isExpanded ? "Masquer" : `Détail (${filieresList.length})`}
                                </span>
                              </td>
                            </tr>

                            {/* Lignes des filières internes dépliées */}
                            {isExpanded && filieresList.map(([nomFiliere, statFil]) => {
                              const resteFil = Math.max(0, statFil.attendu - statFil.encaisse);
                              const tauxFil = statFil.attendu > 0 ? (statFil.encaisse / statFil.attendu) * 100 : 0;
                              return (
                                <tr key={nomFiliere} className="bg-slate-50/80 text-[11px] border-l-4 border-l-blue-500">
                                  <td className="py-2.5 pl-10 pr-4 font-semibold text-slate-700">
                                    ↳ {nomFiliere}
                                  </td>
                                  <td className="py-2.5 px-4 text-center text-slate-600 font-medium">
                                    {statFil.effectif}
                                  </td>
                                  <td className="py-2.5 px-4 text-right text-slate-500">
                                    ~{statFil.effectif > 0 ? Math.round(statFil.attendu / statFil.effectif).toLocaleString('fr-FR') : 0} F
                                  </td>
                                  <td className="py-2.5 px-4 text-right font-bold text-slate-800">
                                    {Math.round(statFil.attendu).toLocaleString('fr-FR')} F
                                  </td>
                                  <td className="py-2.5 px-4 text-right font-semibold text-emerald-700">
                                    {Math.round(statFil.encaisse).toLocaleString('fr-FR')} F
                                  </td>
                                  <td className="py-2.5 px-4 text-right font-semibold text-amber-700">
                                    {Math.round(resteFil).toLocaleString('fr-FR')} F
                                  </td>
                                  <td className="py-2.5 px-4 text-center font-bold text-slate-600">
                                    {tauxFil.toFixed(1)}%
                                  </td>
                                  <td className="py-2.5 px-4 text-center text-slate-400">
                                    —
                                  </td>
                                </tr>
                              );
                            })}
                          </>
                        );
                      })}
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          {/* Tableau Récapitulatif : Inscriptions & Accessoires */}
          <div className="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
            <div className="p-5 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
              <div>
                <h3 className="text-base font-black text-slate-900">
                  Récapitulatif Global des Recettes : Frais d'Inscription vs Scolarité
                </h3>
                <p className="text-xs text-slate-500">
                  Comparaison globale sur l'effectif total ({nbEtudiants} inscrits)
                </p>
              </div>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full border-collapse text-left text-xs">
                <thead>
                  <tr className="bg-slate-100 border-b border-slate-200 font-bold text-slate-700 uppercase">
                    <th className="py-3 px-4">Poste de Recette</th>
                    <th className="py-3 px-4 text-center">Tarif Unitaire Moyen</th>
                    <th className="py-3 px-4 text-right">Total Attendu ({nbEtudiants} inscrits)</th>
                    <th className="py-3 px-4 text-right">Déjà Encaissé</th>
                    <th className="py-3 px-4 text-right">Reste à Recouvrer</th>
                    <th className="py-3 px-4 text-center">Taux Réalisé</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {/* Frais d'inscription */}
                  <tr className="hover:bg-slate-50">
                    <td className="py-3.5 px-4 font-bold text-slate-900">Frais d'Inscription / Dossier</td>
                    <td className="py-3.5 px-4 text-center font-semibold text-slate-600">
                      {customTarifs.prixInscription.toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-right font-black text-slate-900">
                      {Math.round(statsRecettes.attenduInscriptions).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-right font-bold text-emerald-600">
                      {Math.round(statsRecettes.encaisseInscriptions).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-right font-bold text-amber-600">
                      {Math.max(0, statsRecettes.attenduInscriptions - statsRecettes.encaisseInscriptions).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-center font-black text-blue-700">
                      {statsRecettes.attenduInscriptions > 0
                        ? ((statsRecettes.encaisseInscriptions / statsRecettes.attenduInscriptions) * 100).toFixed(1)
                        : 0}%
                    </td>
                  </tr>

                  {/* Polos */}
                  <tr className="hover:bg-slate-50">
                    <td className="py-3.5 px-4 font-bold text-slate-900">Polos Institutionnels</td>
                    <td className="py-3.5 px-4 text-center font-semibold text-slate-600">
                      {customTarifs.prixPolo.toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-right font-black text-slate-900">
                      {Math.round(statsRecettes.attenduPolos).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-right font-bold text-emerald-600">
                      {Math.round(statsRecettes.encaissePolos).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-right font-bold text-amber-600">
                      {Math.max(0, statsRecettes.attenduPolos - statsRecettes.encaissePolos).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-center font-black text-blue-700">
                      {statsRecettes.attenduPolos > 0
                        ? ((statsRecettes.encaissePolos / statsRecettes.attenduPolos) * 100).toFixed(1)
                        : 0}%
                    </td>
                  </tr>

                  {/* Cartes d'étudiant */}
                  <tr className="hover:bg-slate-50">
                    <td className="py-3.5 px-4 font-bold text-slate-900">Cartes d'Étudiant</td>
                    <td className="py-3.5 px-4 text-center font-semibold text-slate-600">
                      {customTarifs.prixCarte.toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-right font-black text-slate-900">
                      {Math.round(statsRecettes.attenduCartes).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-right font-bold text-emerald-600">
                      {Math.round(statsRecettes.encaisseCartes).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-right font-bold text-amber-600">
                      {Math.max(0, statsRecettes.attenduCartes - statsRecettes.encaisseCartes).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-center font-black text-blue-700">
                      {statsRecettes.attenduCartes > 0
                        ? ((statsRecettes.encaisseCartes / statsRecettes.attenduCartes) * 100).toFixed(1)
                        : 0}%
                    </td>
                  </tr>

                  {/* Assurance */}
                  <tr className="hover:bg-slate-50">
                    <td className="py-3.5 px-4 font-bold text-slate-900">Assurance & Frais Divers</td>
                    <td className="py-3.5 px-4 text-center font-semibold text-slate-600">
                      {customTarifs.prixAssurance.toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-right font-black text-slate-900">
                      {Math.round(statsRecettes.attenduAssurance).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-right font-bold text-emerald-600">
                      {Math.round(statsRecettes.encaisseAssurance).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-right font-bold text-amber-600">
                      {Math.max(0, statsRecettes.attenduAssurance - statsRecettes.encaisseAssurance).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-center font-black text-blue-700">
                      {statsRecettes.attenduAssurance > 0
                        ? ((statsRecettes.encaisseAssurance / statsRecettes.attenduAssurance) * 100).toFixed(1)
                        : 0}%
                    </td>
                  </tr>

                  {/* Frais Scolaires - Synthèse des options */}
                  <tr className="hover:bg-slate-50 bg-blue-50/20">
                    <td className="py-3.5 px-4 font-bold text-blue-900">
                      Frais de Scolarité (Total des Options)
                    </td>
                    <td className="py-3.5 px-4 text-center font-semibold text-slate-600">
                      Selon Option
                    </td>
                    <td className="py-3.5 px-4 text-right font-black text-blue-950">
                      {Math.round(statsRecettes.attenduScolariteAnnuelle).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-right font-bold text-emerald-600">
                      {Math.round(statsRecettes.encaisseScolarite).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-right font-bold text-amber-600">
                      {Math.max(0, statsRecettes.attenduScolariteAnnuelle - statsRecettes.encaisseScolarite).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-3.5 px-4 text-center font-black text-blue-700">
                      {statsRecettes.attenduScolariteAnnuelle > 0
                        ? ((statsRecettes.encaisseScolarite / statsRecettes.attenduScolariteAnnuelle) * 100).toFixed(1)
                        : 0}%
                    </td>
                  </tr>

                  {/* Ligne TOTAL GLOBAL */}
                  <tr className="bg-slate-900 text-white font-black text-sm">
                    <td className="py-4 px-4 uppercase">TOTAL GLOBAL PRÉVISIONNEL</td>
                    <td className="py-4 px-4 text-center text-slate-400">—</td>
                    <td className="py-4 px-4 text-right text-emerald-400">
                      {Math.round(statsRecettes.totalAttenduTheorique).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-4 px-4 text-right text-emerald-400">
                      {Math.round(statsRecettes.totalEncaisse).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-4 px-4 text-right text-amber-400">
                      {Math.round(statsRecettes.resteARecouvrer).toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="py-4 px-4 text-center text-white">
                      {statsRecettes.tauxRecouvrementGlobal.toFixed(1)}%
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* ONGLET 2 : PRÉVISION DU MOIS & IDENTIFICATION DES ÉTUDIANTS DÉBITEURS      */}
      {/* ========================================================================= */}
      {activeTab === 'prevision_mois' && (
        <div className="space-y-6">
          {/* Sélection du mois & Bandeau d'échéance calendaire */}
          <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm space-y-4">
            <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
              <div>
                <h3 className="text-base font-black text-slate-900">
                  Prévision & Recouvrement du Mois : {statsMois.moisLabel}
                </h3>
                <p className="text-xs text-slate-500">
                  Suivi distinct du recouvrement des Inscriptions (paiements secrétaire) et des Frais Scolaires (mensualités)
                </p>
              </div>

              <div className="flex flex-wrap items-center gap-3">
                <div className="flex items-center gap-2">
                  <label className="text-xs font-bold text-slate-600 uppercase">Mois :</label>
                  <select
                    value={selectedMoisIndex}
                    onChange={(e) => setSelectedMoisIndex(Number(e.target.value))}
                    className="px-4 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    {MOIS_ANNEE.map(m => (
                      <option key={m.index} value={m.index}>{m.label}</option>
                    ))}
                  </select>
                </div>

                <button
                  onClick={handleExportDebiteursPDF}
                  className="flex items-center gap-2 px-4 py-2 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-bold shadow-xs transition-colors"
                >
                  <Download className="w-3.5 h-3.5 text-emerald-400" />
                  Exporter Débiteurs (PDF)
                </button>
              </div>
            </div>

            {/* Notification de calendrier et de délai de grâce */}
            <div className={cn(
              "p-4 rounded-2xl border flex items-start gap-3 transition-colors",
              statsMois.isEcheanceDepassee
                ? "bg-rose-50 border-rose-200 text-rose-900"
                : "bg-emerald-50 border-emerald-200 text-emerald-950"
            )}>
              <Info className={cn("w-5 h-5 shrink-0 mt-0.5", statsMois.isEcheanceDepassee ? "text-rose-600" : "text-emerald-600")} />
              <div className="text-xs space-y-0.5">
                <div className="font-black text-sm">
                  {statsMois.isEcheanceDepassee
                    ? `Échéance de grâce dépassée pour ${statsMois.moisLabel} (Date limite : 5 ${statsMois.nextMoisNom})`
                    : `Période de règlement en cours pour ${statsMois.moisLabel} — Délai de grâce jusqu'au 5 ${statsMois.nextMoisNom}`}
                </div>
                <p className="opacity-90 leading-relaxed">
                  {statsMois.isEcheanceDepassee
                    ? `Les étudiants n'ayant pas encore versé leur mensualité pour ce mois sont désormais comptabilisés comme débiteurs en retard.`
                    : `L'année académique venant d'ouvrir ses portes, les étudiants disposent jusqu'au 5 ${statsMois.nextMoisNom} pour régulariser leur mensualité de scolarité. Ils ne sont donc pas considérés en retard de scolarité.`}
                </p>
              </div>
            </div>
          </div>

          {/* ========================================================================= */}
          {/* VOLET 1 : RECOUVREMENT DES INSCRIPTIONS & FORMALITÉS DU MOIS             */}
          {/* ========================================================================= */}
          <div className="space-y-3">
            <div className="flex items-center gap-2">
              <span className="w-2.5 h-2.5 rounded-full bg-blue-600"></span>
              <h4 className="text-sm font-black text-slate-900 uppercase tracking-wide">
                1. Recouvrement des Inscriptions & Formalités d'Entrée ({statsMois.moisNom})
              </h4>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div className="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm space-y-1.5">
                <div className="text-[11px] font-bold text-slate-500 uppercase">Inscrits de la Cohorte</div>
                <div className="text-2xl font-black text-slate-900">
                  {statsMois.nbInscritsMois} Étudiants
                </div>
                <p className="text-[11px] text-slate-400">
                  Attendu inscription : {statsMois.objectifInscriptionsMois.toLocaleString('fr-FR')} F
                </p>
              </div>

              <div className="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm space-y-1.5">
                <div className="text-[11px] font-bold text-slate-500 uppercase">Encaissé Inscriptions + Frais</div>
                <div className="text-2xl font-black text-emerald-600">
                  {statsMois.encaisseTotalFormalitesMois.toLocaleString('fr-FR')} FCFA
                </div>
                <p className="text-[11px] text-slate-400">
                  Inscriptions ({statsMois.encaisseInscriptionsMois.toLocaleString('fr-FR')} F) + Accessoires ({statsMois.encaisseAccessoiresMois.toLocaleString('fr-FR')} F)
                </p>
              </div>

              <div className="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm space-y-1.5">
                <div className="text-[11px] font-bold text-slate-500 uppercase">Inscriptions Réglées (Caisse)</div>
                <div className="text-2xl font-black text-blue-600 flex items-center gap-2">
                  <span>{statsMois.countInscriptionsPayees}</span>
                  <span className="text-xs font-bold text-blue-500 bg-blue-50 px-2 py-0.5 rounded-full">
                    {((statsMois.countInscriptionsPayees / (statsMois.nbInscritsMois || 1)) * 100).toFixed(0)}%
                  </span>
                </div>
                <p className="text-[11px] text-slate-400">
                  Paiement de dossier validé et lié
                </p>
              </div>

              <div className={cn(
                "rounded-3xl p-5 border shadow-sm space-y-1.5",
                statsMois.debiteursInscription.length > 0 ? "bg-amber-50 border-amber-200" : "bg-emerald-50 border-emerald-200"
              )}>
                <div className="text-[11px] font-bold uppercase text-amber-900">
                  Inscriptions Non Soldées (Secrétaire)
                </div>
                <div className="text-2xl font-black text-amber-700">
                  {statsMois.debiteursInscription.length} Étudiants
                </div>
                <p className="text-[11px] text-amber-800 font-medium">
                  {statsMois.totalDetteInscription.toLocaleString('fr-FR')} FCFA en attente de versement
                </p>
              </div>
            </div>
          </div>

          {/* ========================================================================= */}
          {/* VOLET 2 : RECOUVREMENT DES FRAIS SCOLAIRES (MENSUALITÉS DU MOIS)         */}
          {/* ========================================================================= */}
          <div className="space-y-3">
            <div className="flex items-center gap-2">
              <span className="w-2.5 h-2.5 rounded-full bg-indigo-600"></span>
              <h4 className="text-sm font-black text-slate-900 uppercase tracking-wide">
                2. Recouvrement des Frais Scolaires (Mensualités de {statsMois.moisNom})
              </h4>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div className="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm space-y-1.5">
                <div className="text-[11px] font-bold text-slate-500 uppercase">Mensualités Scolarité Dues ({statsMois.moisNom})</div>
                <div className="text-2xl font-black text-slate-900">
                  {Math.round(statsMois.objectifMensuelScolarite).toLocaleString('fr-FR')} FCFA
                </div>
                <p className="text-[11px] text-slate-400">
                  Calculé sur les {nbEtudiants} inscrits selon leur option & niveau
                </p>
              </div>

              <div className="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm space-y-1.5">
                <div className="text-[11px] font-bold text-slate-500 uppercase">Encaissé en Scolarité ({statsMois.moisNom})</div>
                <div className="text-2xl font-black text-emerald-600">
                  {Math.round(statsMois.encaisseScolariteMois).toLocaleString('fr-FR')} FCFA
                </div>
                <p className="text-[11px] text-slate-400">
                  Paiements enregistrés de type scolarité
                </p>
              </div>

              <div className={cn(
                "rounded-3xl p-5 border shadow-sm space-y-1.5",
                statsMois.isEcheanceDepassee && statsMois.debiteursScolarite.length > 0
                  ? "bg-rose-50 border-rose-200 text-rose-950"
                  : "bg-emerald-50 border-emerald-200 text-emerald-950"
              )}>
                <div className="text-[11px] font-bold uppercase">
                  {statsMois.isEcheanceDepassee ? "Arriérés Exigibles de Scolarité" : "Statut Échéance Scolarité"}
                </div>
                <div className="text-2xl font-black">
                  {statsMois.isEcheanceDepassee
                    ? `${Math.round(statsMois.totalDetteScolarite).toLocaleString('fr-FR')} FCFA`
                    : "0 FCFA d'Arriéré"}
                </div>
                <p className="text-[11px] opacity-80">
                  {statsMois.isEcheanceDepassee
                    ? `${statsMois.debiteursScolarite.length} étudiant(s) en retard pour ${statsMois.moisLabel}`
                    : `Délai de grâce actif jusqu'au 5 ${statsMois.nextMoisNom}`}
                </p>
              </div>
            </div>
          </div>

          {/* ========================================================================= */}
          {/* TABLEAU DES DÉBITEURS & IMPAYÉS AVEC SÉLECTEUR DE TYPE DE DETTE           */}
          {/* ========================================================================= */}
          <div className="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden space-y-0">
            {/* Header du tableau avec tabs et recherche */}
            <div className="p-5 bg-slate-50 border-b border-slate-200 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
              <div className="space-y-1">
                <h4 className="text-base font-black text-slate-900">
                  Contrôle Nominatif des Débiteurs & Impayés
                </h4>
                <p className="text-xs text-slate-500">
                  Identifiez les inscriptions non payées par la secrétaire ou les retards de scolarité
                </p>
              </div>

              {/* Barre de recherche */}
              <div className="flex items-center gap-3">
                <div className="relative">
                  <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                  <input
                    type="text"
                    value={searchDebiteur}
                    onChange={(e) => setSearchDebiteur(e.target.value)}
                    placeholder="Rechercher étudiant / matricule..."
                    className="pl-9 pr-3 py-1.5 bg-white border border-slate-300 rounded-xl text-xs font-bold text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>
            </div>

            {/* Sélecteur des sous-onglets de débiteurs */}
            <div className="px-5 pt-4 pb-2 bg-white border-b border-slate-100 flex flex-wrap items-center gap-2">
              <button
                onClick={() => setDebiteurTab('inscriptions')}
                className={cn(
                  "px-4 py-2 rounded-xl text-xs font-black transition-all flex items-center gap-2",
                  debiteurTab === 'inscriptions'
                    ? "bg-amber-500 text-white shadow-xs"
                    : "bg-slate-100 hover:bg-slate-200 text-slate-700"
                )}
              >
                <AlertTriangle className="w-3.5 h-3.5" />
                <span>Inscriptions Non Soldées (Secrétaire)</span>
                <span className={cn(
                  "px-2 py-0.5 rounded-full text-[10px]",
                  debiteurTab === 'inscriptions' ? "bg-amber-600 text-white" : "bg-amber-100 text-amber-900"
                )}>
                  {statsMois.debiteursInscription.length}
                </span>
              </button>

              <button
                onClick={() => setDebiteurTab('scolarite')}
                className={cn(
                  "px-4 py-2 rounded-xl text-xs font-black transition-all flex items-center gap-2",
                  debiteurTab === 'scolarite'
                    ? "bg-indigo-600 text-white shadow-xs"
                    : "bg-slate-100 hover:bg-slate-200 text-slate-700"
                )}
              >
                <Calendar className="w-3.5 h-3.5" />
                <span>Arriérés de Scolarité ({statsMois.moisNom})</span>
                <span className={cn(
                  "px-2 py-0.5 rounded-full text-[10px]",
                  debiteurTab === 'scolarite' ? "bg-indigo-700 text-white" : "bg-indigo-100 text-indigo-900"
                )}>
                  {statsMois.debiteursScolarite.length}
                </span>
              </button>

              <button
                onClick={() => setDebiteurTab('tous')}
                className={cn(
                  "px-4 py-2 rounded-xl text-xs font-black transition-all flex items-center gap-2",
                  debiteurTab === 'tous'
                    ? "bg-slate-900 text-white shadow-xs"
                    : "bg-slate-100 hover:bg-slate-200 text-slate-700"
                )}
              >
                <UserX className="w-3.5 h-3.5" />
                <span>Vue Consolidée</span>
              </button>
            </div>

            {/* TAB 1 : DÉBITEURS D'INSCRIPTION */}
            {debiteurTab === 'inscriptions' && (
              <div className="overflow-x-auto">
                {statsMois.debiteursInscription.length === 0 ? (
                  <div className="p-8 text-center text-slate-500 space-y-2">
                    <CheckCircle2 className="w-10 h-10 text-emerald-500 mx-auto" />
                    <p className="font-bold text-sm text-slate-800">Toutes les inscriptions sont soldées !</p>
                    <p className="text-xs">Chaque fiche d'inscription est bien liée à un versement d'inscription.</p>
                  </div>
                ) : (
                  <table className="w-full border-collapse text-left text-xs">
                    <thead>
                      <tr className="bg-slate-100 border-b border-slate-200 font-bold text-slate-700 uppercase">
                        <th className="py-3 px-4">Matricule</th>
                        <th className="py-3 px-4">Nom et Prénom</th>
                        <th className="py-3 px-4">Filière / Niveau</th>
                        <th className="py-3 px-4">Contact</th>
                        <th className="py-3 px-4 text-right">Frais Inscription Dus</th>
                        <th className="py-3 px-4 text-right">Déjà Versé</th>
                        <th className="py-3 px-4 text-right">Dette Inscription</th>
                        <th className="py-3 px-4 text-center">Statut Secrétaire</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {statsMois.debiteursInscription
                        .filter(d => {
                          const q = searchDebiteur.toLowerCase();
                          return !q ||
                            d.etudiant.nom.toLowerCase().includes(q) ||
                            d.etudiant.prenom.toLowerCase().includes(q) ||
                            d.etudiant.matricule.toLowerCase().includes(q);
                        })
                        .map((item) => (
                          <tr key={item.etudiant.id || item.etudiant.matricule} className="hover:bg-amber-50/40 transition-colors">
                            <td className="py-3 px-4 font-mono font-black text-blue-700">
                              {item.etudiant.matricule}
                            </td>
                            <td className="py-3 px-4 font-bold text-slate-900">
                              {item.etudiant.nom} {item.etudiant.prenom}
                            </td>
                            <td className="py-3 px-4 text-slate-600">
                              {item.etudiant.filiere} ({item.etudiant.niveau})
                            </td>
                            <td className="py-3 px-4 text-slate-600 flex items-center gap-1.5">
                              <Phone className="w-3.5 h-3.5 text-slate-400" />
                              <span>{item.etudiant.telephone || 'N/A'}</span>
                            </td>
                            <td className="py-3 px-4 text-right font-medium text-slate-600">
                              {item.fraisDus.toLocaleString('fr-FR')} F
                            </td>
                            <td className="py-3 px-4 text-right font-semibold text-emerald-600">
                              {item.dejaPaye.toLocaleString('fr-FR')} F
                            </td>
                            <td className="py-3 px-4 text-right font-black text-rose-600">
                              {item.resteDu.toLocaleString('fr-FR')} FCFA
                            </td>
                            <td className="py-3 px-4 text-center">
                              <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black bg-amber-100 text-amber-800 border border-amber-200">
                                <Clock className="w-3 h-3 text-amber-600" />
                                Non lié par secrétaire
                              </span>
                            </td>
                          </tr>
                        ))}
                    </tbody>
                  </table>
                )}
              </div>
            )}

            {/* TAB 2 : ARRIÉRÉS DE SCOLARITÉ */}
            {debiteurTab === 'scolarite' && (
              <div>
                {!statsMois.isEcheanceDepassee ? (
                  <div className="p-8 text-center space-y-3 bg-emerald-50/30">
                    <CheckCircle2 className="w-12 h-12 text-emerald-500 mx-auto" />
                    <div className="max-w-md mx-auto space-y-1">
                      <h5 className="font-black text-base text-emerald-950">
                        Aucun étudiant en retard de scolarité pour {statsMois.moisLabel}
                      </h5>
                      <p className="text-xs text-slate-600 leading-relaxed">
                        L'année académique venant de débuter en Octobre, les étudiants disposent d'un délai réglementaire jusqu'au <strong>5 {statsMois.nextMoisNom}</strong> pour régler leur tranche de scolarité. Par conséquent, aucune dette de scolarité n'est encore exigible ou imputée aux étudiants.
                      </p>
                    </div>
                  </div>
                ) : statsMois.debiteursScolarite.length === 0 ? (
                  <div className="p-8 text-center text-slate-500 space-y-2">
                    <CheckCircle2 className="w-10 h-10 text-emerald-500 mx-auto" />
                    <p className="font-bold text-sm text-slate-800">Aucun arriéré de scolarité !</p>
                    <p className="text-xs">Tous les étudiants sont à jour de leur scolarité pour {statsMois.moisLabel}.</p>
                  </div>
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-left text-xs">
                      <thead>
                        <tr className="bg-slate-100 border-b border-slate-200 font-bold text-slate-700 uppercase">
                          <th className="py-3 px-4">Matricule</th>
                          <th className="py-3 px-4">Nom et Prénom</th>
                          <th className="py-3 px-4">Filière / Niveau</th>
                          <th className="py-3 px-4">Contact</th>
                          <th className="py-3 px-4 text-right">Mensualité</th>
                          <th className="py-3 px-4 text-right">Déjà Versé</th>
                          <th className="py-3 px-4 text-right">Arriéré Scolarité</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {statsMois.debiteursScolarite
                          .filter(d => {
                            const q = searchDebiteur.toLowerCase();
                            return !q ||
                              d.etudiant.nom.toLowerCase().includes(q) ||
                              d.etudiant.prenom.toLowerCase().includes(q) ||
                              d.etudiant.matricule.toLowerCase().includes(q);
                          })
                          .map((item) => (
                            <tr key={item.etudiant.id || item.etudiant.matricule} className="hover:bg-rose-50/30">
                              <td className="py-3 px-4 font-mono font-black text-blue-700">
                                {item.etudiant.matricule}
                              </td>
                              <td className="py-3 px-4 font-bold text-slate-900">
                                {item.etudiant.nom} {item.etudiant.prenom}
                              </td>
                              <td className="py-3 px-4 text-slate-600">
                                {item.etudiant.filiere} ({item.etudiant.niveau})
                              </td>
                              <td className="py-3 px-4 text-slate-600 flex items-center gap-1.5">
                                <Phone className="w-3.5 h-3.5 text-slate-400" />
                                <span>{item.etudiant.telephone || 'N/A'}</span>
                              </td>
                              <td className="py-3 px-4 text-right font-medium text-slate-600">
                                {Math.round(item.mensualiteAttendue).toLocaleString('fr-FR')} F
                              </td>
                              <td className="py-3 px-4 text-right font-semibold text-emerald-600">
                                {Math.round(item.totalScolaritePayee).toLocaleString('fr-FR')} F
                              </td>
                              <td className="py-3 px-4 text-right font-black text-rose-600">
                                {Math.round(item.arrieres).toLocaleString('fr-FR')} FCFA
                              </td>
                            </tr>
                          ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            )}

            {/* TAB 3 : TOUS LES DÉBITEURS */}
            {debiteurTab === 'tous' && (
              <div className="overflow-x-auto">
                <table className="w-full border-collapse text-left text-xs">
                  <thead>
                    <tr className="bg-slate-100 border-b border-slate-200 font-bold text-slate-700 uppercase">
                      <th className="py-3 px-4">Matricule</th>
                      <th className="py-3 px-4">Nom et Prénom</th>
                      <th className="py-3 px-4">Filière / Niveau</th>
                      <th className="py-3 px-4 text-right">Dette Inscription</th>
                      <th className="py-3 px-4 text-right">Arriéré Scolarité</th>
                      <th className="py-3 px-4 text-right">Dette Totale</th>
                      <th className="py-3 px-4 text-center">Type de Déficit</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {etudiants
                      .map(e => {
                        const debInsc = statsMois.debiteursInscription.find(d => d.etudiant.matricule === e.matricule);
                        const debScol = statsMois.debiteursScolarite.find(d => d.etudiant.matricule === e.matricule);
                        const detteInsc = debInsc ? debInsc.resteDu : 0;
                        const detteScol = debScol ? debScol.arrieres : 0;
                        const totalDette = detteInsc + detteScol;
                        return { etudiant: e, detteInsc, detteScol, totalDette };
                      })
                      .filter(item => {
                        if (item.totalDette <= 0) return false;
                        const q = searchDebiteur.toLowerCase();
                        return !q ||
                          item.etudiant.nom.toLowerCase().includes(q) ||
                          item.etudiant.prenom.toLowerCase().includes(q) ||
                          item.etudiant.matricule.toLowerCase().includes(q);
                      })
                      .map((item) => (
                        <tr key={item.etudiant.id || item.etudiant.matricule} className="hover:bg-slate-50">
                          <td className="py-3 px-4 font-mono font-black text-blue-700">
                            {item.etudiant.matricule}
                          </td>
                          <td className="py-3 px-4 font-bold text-slate-900">
                            {item.etudiant.nom} {item.etudiant.prenom}
                          </td>
                          <td className="py-3 px-4 text-slate-600">
                            {item.etudiant.filiere} ({item.etudiant.niveau})
                          </td>
                          <td className="py-3 px-4 text-right font-medium text-amber-700">
                            {item.detteInsc > 0 ? `${item.detteInsc.toLocaleString('fr-FR')} F` : '0 F'}
                          </td>
                          <td className="py-3 px-4 text-right font-medium text-indigo-700">
                            {item.detteScol > 0 ? `${Math.round(item.detteScol).toLocaleString('fr-FR')} F` : '0 F'}
                          </td>
                          <td className="py-3 px-4 text-right font-black text-rose-600">
                            {Math.round(item.totalDette).toLocaleString('fr-FR')} FCFA
                          </td>
                          <td className="py-3 px-4 text-center">
                            {item.detteInsc > 0 && item.detteScol > 0 ? (
                              <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800">
                                Inscription + Scolarité
                              </span>
                            ) : item.detteInsc > 0 ? (
                              <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">
                                Inscription Seule
                              </span>
                            ) : (
                              <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 text-indigo-800">
                                Scolarité Seule
                              </span>
                            )}
                          </td>
                        </tr>
                      ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* ONGLET 3 : RETOUR SUR INVESTISSEMENT (ROI) & SEUIL DE RENTABILITÉ          */}
      {/* ========================================================================= */}
      {activeTab === 'roi' && (
        <div className="space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div className="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm space-y-2">
              <div className="text-xs font-bold text-slate-500 uppercase">Total Charges Engagées</div>
              <div className="text-2xl font-black text-slate-900">
                {totalCharges.toLocaleString('fr-FR')} FCFA
              </div>
              <p className="text-[11px] text-slate-400">
                Dépenses ({depensesTotal.toLocaleString('fr-FR')} F) + Salaires ({salairesTotal.toLocaleString('fr-FR')} F)
              </p>
            </div>

            <div className="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm space-y-2">
              <div className="text-xs font-bold text-slate-500 uppercase">Bénéfice Net Réel Actuel</div>
              <div className={cn("text-2xl font-black", statsRoi.beneficeNetReel >= 0 ? "text-emerald-600" : "text-rose-600")}>
                {Math.round(statsRoi.beneficeNetReel).toLocaleString('fr-FR')} FCFA
              </div>
              <p className="text-[11px] text-slate-400">
                Revenus réels encaissés ({Math.round(statsRoi.recettesReelles).toLocaleString('fr-FR')} F) - Charges
              </p>
            </div>

            <div className="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm space-y-2">
              <div className="text-xs font-bold text-slate-500 uppercase">Seuil de Rentabilité (Break-Even)</div>
              <div className="text-2xl font-black text-indigo-700">
                {statsRoi.etudiantsRequisPourSeuil} Étudiants Requis
              </div>
              <p className="text-[11px] text-slate-400">
                Effectif actuel : {nbEtudiants} inscrits ({statsRoi.ecartSeuilEtudiants >= 0 ? `+${statsRoi.ecartSeuilEtudiants} au-dessus du seuil` : `${statsRoi.ecartSeuilEtudiants} pour rentabiliser`})
              </p>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* ONGLET 4 : PROJECTION ANNUELLE SUR 10 MOIS                                 */}
      {/* ========================================================================= */}
      {activeTab === 'projection_annuelle' && (
        <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm space-y-4">
          <h3 className="text-base font-black text-slate-900">
            Projection Budgétaire des Encaissements sur l'Année Académique (10 Mois)
          </h3>
          <div className="overflow-x-auto">
            <table className="w-full border-collapse text-left text-xs">
              <thead>
                <tr className="bg-slate-100 border-b border-slate-200 font-bold text-slate-700 uppercase">
                  <th className="py-3 px-4">Mois Académique</th>
                  <th className="py-3 px-4 text-right">Mensualité Attendue (Total)</th>
                  <th className="py-3 px-4 text-right">Objectif Cumulé</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {MOIS_ANNEE.map((m, idx) => {
                  const monthlyTarget = statsRecettes.attenduScolariteAnnuelle / 10;
                  const cumul = monthlyTarget * (idx + 1);
                  return (
                    <tr key={m.index} className="hover:bg-slate-50">
                      <td className="py-3 px-4 font-bold text-slate-900">{m.label}</td>
                      <td className="py-3 px-4 text-right font-semibold text-blue-700">
                        {Math.round(monthlyTarget).toLocaleString('fr-FR')} FCFA
                      </td>
                      <td className="py-3 px-4 text-right font-black text-slate-900">
                        {Math.round(cumul).toLocaleString('fr-FR')} FCFA
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
export default PrevisionsRoiPage;
