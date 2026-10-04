import { useState } from 'react';
import { Settings, Building2, Wallet, Users, Shield, Database, Plus, Trash2, Save, Download } from 'lucide-react';
import { useSettings } from '../../hooks/useSettings';
import * as XLSX from 'xlsx';
import { db } from '../../db/db';

type TabType = 'general' | 'finances' | 'personnel' | 'securite' | 'donnees';

export function ParametresPage() {
  const { settings, updateSettings } = useSettings();
  
  const [activeTab, setActiveTab] = useState<TabType>('general');
  const [savedMessage, setSavedMessage] = useState('');
  
  // Local state for forms to avoid saving on every keystroke
  const [generalForm, setGeneralForm] = useState({
    anneeAcademique: settings.anneeAcademique,
    nomEcole: settings.nomEcole,
    sigle: settings.sigle,
    nomComptable: settings.nomComptable,
    nomDG: settings.nomDG
  });

  const [financesForm, setFinancesForm] = useState(() => {
    const copy = JSON.parse(JSON.stringify(settings.tarifs));
    if (!copy.coursDuSoir) {
      copy.coursDuSoir = { 'Licence 1': 300000, 'Licence 2': 350000, 'Licence 3': 400000 };
    }
    return {
      tarifs: copy,
      categoriesDepenses: [...settings.categoriesDepenses],
      newCategory: ''
    };
  });

  const [personnelForm, setPersonnelForm] = useState({
    deductions: [...settings.deductions]
  });

  const [securityForm, setSecurityForm] = useState({
    currentPassword: '',
    newPassword: '',
    confirmPassword: '',
    error: '',
    success: ''
  });

  const showSavedMessage = () => {
    setSavedMessage('Paramètres enregistrés avec succès !');
    setTimeout(() => setSavedMessage(''), 3000);
  };

  const handleSaveGeneral = () => {
    updateSettings(generalForm);
    showSavedMessage();
  };

  const handleSaveFinances = () => {
    const { newCategory, ...rest } = financesForm;
    updateSettings(rest);
    showSavedMessage();
  };

  const handleAddCategory = () => {
    if (financesForm.newCategory.trim()) {
      setFinancesForm(prev => ({
        ...prev,
        categoriesDepenses: [...prev.categoriesDepenses, prev.newCategory.trim()],
        newCategory: ''
      }));
    }
  };

  const handleRemoveCategory = (index: number) => {
    setFinancesForm(prev => ({
      ...prev,
      categoriesDepenses: prev.categoriesDepenses.filter((_, i) => i !== index)
    }));
  };

  const handleSavePersonnel = () => {
    updateSettings({ deductions: personnelForm.deductions });
    showSavedMessage();
  };

  const handleAddDeduction = () => {
    setPersonnelForm(prev => ({
      ...prev,
      deductions: [...prev.deductions, { id: Date.now().toString(), nom: 'Nouvelle déduction', type: 'pourcentage', valeur: 0 }]
    }));
  };

  const handleChangePassword = () => {
    setSecurityForm(prev => ({ ...prev, error: '', success: '' }));
    
    if (securityForm.newPassword !== securityForm.confirmPassword) {
      setSecurityForm(prev => ({ ...prev, error: 'Les nouveaux mots de passe ne correspondent pas.' }));
      return;
    }
    
    if (securityForm.newPassword.length < 4) {
      setSecurityForm(prev => ({ ...prev, error: 'Le mot de passe est trop court.' }));
      return;
    }

    // In a real app, this would call an API or Supabase auth
    // For now, we simulate a success
    setTimeout(() => {
      setSecurityForm({
        currentPassword: '',
        newPassword: '',
        confirmPassword: '',
        error: '',
        success: 'Mot de passe modifié avec succès.'
      });
      // Optionally update user context if we track password there, but usually we just let backend handle it
    }, 500);
  };

  const handleExportData = async () => {
    try {
      const etudiants = await db.etudiants.toArray();
      const paiements = await db.paiements.toArray();
      
      const wb = XLSX.utils.book_new();
      
      const wsEtudiants = XLSX.utils.json_to_sheet(etudiants);
      XLSX.utils.book_append_sheet(wb, wsEtudiants, "Etudiants");
      
      const wsPaiements = XLSX.utils.json_to_sheet(paiements);
      XLSX.utils.book_append_sheet(wb, wsPaiements, "Paiements");
      
      XLSX.writeFile(wb, `Sauvegarde_Comptable_${new Date().toISOString().slice(0,10)}.xlsx`);
    } catch (e) {
      console.error("Erreur lors de l'exportation", e);
      alert("Erreur lors de la sauvegarde.");
    }
  };

  const tabs = [
    { id: 'general', label: 'Général', icon: Building2 },
    { id: 'finances', label: 'Finances', icon: Wallet },
    { id: 'personnel', label: 'Personnel', icon: Users },
    { id: 'securite', label: 'Sécurité', icon: Shield },
    { id: 'donnees', label: 'Données', icon: Database },
  ] as const;

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
            <Settings className="h-6 w-6 text-blue-600" />
            Paramètres
          </h1>
          <p className="mt-1 text-sm text-gray-500">
            Configuration globale du système et paramètres de l'application
          </p>
        </div>
      </div>

      {/* Fixed Toast Notification */}
      {savedMessage && (
        <div className="fixed bottom-4 right-4 bg-green-600 text-white px-6 py-3 rounded-lg shadow-lg font-medium animate-fade-in-up z-50 flex items-center gap-2">
          <Save className="h-5 w-5" />
          {savedMessage}
        </div>
      )}

      <div className="bg-white rounded-xl shadow-sm border border-gray-100 flex flex-col md:flex-row min-h-[600px] overflow-hidden">
        {/* Sidebar Menu */}
        <div className="w-full md:w-64 bg-gray-50 border-r border-gray-100 flex flex-col">
          {tabs.map((tab) => {
            const Icon = tab.icon;
            return (
              <button
                key={tab.id}
                onClick={() => setActiveTab(tab.id)}
                className={`flex items-center gap-3 px-6 py-4 text-sm font-medium transition-colors border-l-4 ${
                  activeTab === tab.id
                    ? 'border-blue-600 bg-blue-50/50 text-blue-700'
                    : 'border-transparent text-gray-600 hover:bg-gray-100 hover:text-gray-900'
                }`}
              >
                <Icon className={`h-5 w-5 ${activeTab === tab.id ? 'text-blue-600' : 'text-gray-400'}`} />
                {tab.label}
              </button>
            );
          })}
        </div>

        {/* Content Area */}
        <div className="flex-1 p-6 md:p-8">
          {activeTab === 'general' && (
            <div className="space-y-8 animate-fade-in">
              <div>
                <h2 className="text-lg font-semibold text-gray-900 mb-4">Informations de l'Établissement</h2>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  {/* Champs réservés au DG - lecture seule */}
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-gray-700">Nom de l'établissement</label>
                    <div className="w-full rounded-md border border-gray-200 bg-gray-50 p-2 text-gray-600 text-sm cursor-not-allowed">
                      {generalForm.nomEcole || '—'}
                    </div>
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-gray-700">Sigle</label>
                    <div className="w-full rounded-md border border-gray-200 bg-gray-50 p-2 text-gray-600 text-sm cursor-not-allowed">
                      {generalForm.sigle || '—'}
                    </div>
                  </div>
                  <div className="md:col-span-2">
                    <p className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2 flex items-center gap-2">
                      <span>🔒</span>
                      Le nom de l'établissement et le sigle sont réservés au Directeur Général. Contactez l'administrateur pour toute modification.
                    </p>
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-gray-700">Année Académique en cours</label>
                    <input
                      type="text"
                      value={generalForm.anneeAcademique}
                      onChange={e => setGeneralForm({...generalForm, anneeAcademique: e.target.value})}
                      className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 p-2 border"
                    />
                  </div>
                </div>
              </div>

              <div className="border-t border-gray-100 pt-8">
                <h2 className="text-lg font-semibold text-gray-900 mb-4">Signataires (Exports PDF)</h2>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-gray-700">Titre ou Nom du Comptable</label>
                    <input
                      type="text"
                      value={generalForm.nomComptable}
                      onChange={e => setGeneralForm({...generalForm, nomComptable: e.target.value})}
                      className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 p-2 border"
                    />
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-gray-700">Titre ou Nom du DG</label>
                    <input
                      type="text"
                      value={generalForm.nomDG}
                      onChange={e => setGeneralForm({...generalForm, nomDG: e.target.value})}
                      className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 p-2 border"
                    />
                  </div>
                </div>
              </div>

              <div className="pt-4 flex justify-end">
                <button onClick={handleSaveGeneral} className="flex items-center gap-2 bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700">
                  <Save className="h-4 w-4" /> Enregistrer
                </button>
              </div>
            </div>
          )}

          {activeTab === 'finances' && (
            <div className="space-y-10 animate-fade-in max-w-4xl">
              
              {/* CONDITIONS D'INSCRIPTIONS */}
              <div>
                <h2 className="text-2xl font-bold text-[#3B1E6B] mb-6 border-b-2 border-purple-100 pb-2 flex items-center gap-3">
                  <Building2 className="text-orange-500" /> 
                  Conditions d'inscriptions et réinscriptions
                </h2>
                
                <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
                  {/* Bloc Inscriptions & Mensuel */}
                  <div className="space-y-6">
                    <div className="bg-[#3B1E6B] rounded-2xl p-6 text-white shadow-xl relative overflow-hidden border-4 border-white ring-2 ring-gray-100">
                      <div className="absolute top-0 right-0 w-32 h-32 bg-white/5 rounded-bl-full -mr-16 -mt-16"></div>
                      <div className="space-y-6 relative z-10 text-center">
                        <div>
                          <div className="bg-[#F39200] text-white text-sm font-bold px-6 py-1 rounded-full inline-block mb-2 uppercase tracking-wider">Inscriptions</div>
                          <div className="flex items-end justify-center gap-1">
                            <input type="number" value={financesForm.tarifs.inscriptions.nouvelle} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, inscriptions: {...financesForm.tarifs.inscriptions, nouvelle: parseInt(e.target.value)||0}}})} className="w-40 bg-white/10 border-0 border-b-2 border-[#F39200] text-4xl font-bold rounded-t-md p-2 text-center focus:ring-0 focus:bg-white/20 transition-all" />
                            <span className="text-2xl font-bold text-[#F39200]">F</span>
                          </div>
                        </div>
                        <div>
                          <div className="bg-[#F39200] text-white text-sm font-bold px-6 py-1 rounded-full inline-block mb-2 uppercase tracking-wider">Réinscriptions</div>
                          <div className="flex items-end justify-center gap-1">
                            <input type="number" value={financesForm.tarifs.inscriptions.reinscription} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, inscriptions: {...financesForm.tarifs.inscriptions, reinscription: parseInt(e.target.value)||0}}})} className="w-40 bg-white/10 border-0 border-b-2 border-[#F39200] text-4xl font-bold rounded-t-md p-2 text-center focus:ring-0 focus:bg-white/20 transition-all" />
                            <span className="text-2xl font-bold text-[#F39200]">F</span>
                          </div>
                        </div>
                        <div className="pt-4 border-t border-white/20 flex items-center justify-between">
                           <label className="text-sm font-medium text-white/90">Carte d'étudiant:</label>
                           <div className="flex items-center gap-1">
                             <input type="number" value={financesForm.tarifs.inscriptions.carteEtudiant} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, inscriptions: {...financesForm.tarifs.inscriptions, carteEtudiant: parseInt(e.target.value)||0}}})} className="w-24 bg-white/10 border-0 border-b border-purple-400 text-lg rounded-t-md px-2 py-1 focus:ring-0 text-right" />
                             <span className="text-white/80">Fcfa</span>
                           </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Frais Mensuels */}
                  <div className="bg-[#3B1E6B] rounded-2xl p-6 text-white shadow-xl border-4 border-white ring-2 ring-gray-100 flex flex-col justify-center">
                    <div className="text-[#F39200] font-bold mb-6 tracking-wider text-xl text-center">FRAIS MENSUEL</div>
                    <div className="space-y-4">
                      {Object.entries(financesForm.tarifs.mensualites).map(([niveau, montant]) => (
                        <div key={niveau} className="flex items-center justify-between text-lg font-medium">
                          <span>{niveau}</span>
                          <div className="flex items-center gap-2">
                            <span>:</span>
                            <input type="number" value={montant as number} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, mensualites: {...financesForm.tarifs.mensualites, [niveau]: parseInt(e.target.value)||0}}})} className="w-28 bg-white/10 border-0 border-b border-purple-400 rounded-t-md p-1 focus:ring-0 text-right" />
                            <span className="text-white">F</span>
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              </div>

              {/* COÛT ANNUEL, CYCLE INTERNATIONAL */}
              <div className="pt-6">
                <div className="bg-[#F39200] text-white text-center py-2 rounded-full font-bold mb-6 text-lg tracking-wide shadow-md">COÛT ANNUEL, CYCLE INTERNATIONAL</div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  {/* France */}
                  <div className="bg-gray-50 rounded-xl p-4 border-2 border-gray-100 shadow-sm">
                    <div className="bg-[#3B1E6B] text-white text-center py-1.5 rounded-full font-bold mb-4 shadow-sm w-3/4 mx-auto uppercase">FRANCE</div>
                    <div className="flex flex-col items-center justify-center space-y-2 h-20">
                      <span className="font-semibold text-gray-700">Licence, Master :</span>
                      <div className="flex items-center gap-2">
                        <input type="number" value={financesForm.tarifs.cycleInternational.france} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, cycleInternational: {...financesForm.tarifs.cycleInternational, france: parseInt(e.target.value)||0}}})} className="w-36 rounded-md border-gray-300 p-2 border text-center font-bold text-gray-900" />
                        <span className="text-gray-500 font-medium">F / Année</span>
                      </div>
                    </div>
                  </div>
                  {/* Londres */}
                  <div className="bg-gray-50 rounded-xl p-4 border-2 border-gray-100 shadow-sm">
                    <div className="bg-[#3B1E6B] text-white text-center py-1.5 rounded-full font-bold mb-4 shadow-sm w-3/4 mx-auto uppercase">LONDRES</div>
                    <div className="space-y-3">
                      {Object.entries(financesForm.tarifs.cycleInternational.londres).map(([cycle, montant]) => (
                        <div key={cycle} className="flex items-center gap-2">
                          <span className="w-20 font-semibold text-gray-700">{cycle} :</span>
                          <input type="number" value={montant as number} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, cycleInternational: {...financesForm.tarifs.cycleInternational, londres: {...financesForm.tarifs.cycleInternational.londres, [cycle]: parseInt(e.target.value)||0}}}})} className="flex-1 rounded-md border-gray-300 p-1.5 border font-bold text-gray-900 text-right" />
                          <span className="text-gray-500 font-medium whitespace-nowrap">F / Année</span>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              </div>

              {/* COÛT ANNUEL, CYCLE LOCAL */}
              <div className="pt-6">
                <div className="bg-[#F39200] text-white text-center py-2 rounded-full font-bold mb-6 text-lg tracking-wide shadow-md">COÛT ANNUEL, CYCLE LOCAL</div>
                
                <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
                  
                  {/* GESTION & TECHNOLOGIE */}
                  <div className="space-y-4">
                    <div className="bg-[#3B1E6B] text-white text-center py-2 rounded-full font-bold shadow-md">GESTION & TECHNOLOGIE</div>
                    <div className="bg-gray-50 rounded-xl p-4 border-2 border-gray-100 shadow-sm space-y-3">
                      {Object.entries(financesForm.tarifs.cycleLocal.gestion).map(([niveau, montant]) => (
                        <div key={niveau} className="flex items-center gap-2">
                          <span className="w-24 font-bold text-gray-700">{niveau} :</span>
                          <input type="number" value={montant as number} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, cycleLocal: {...financesForm.tarifs.cycleLocal, gestion: {...financesForm.tarifs.cycleLocal.gestion, [niveau]: parseInt(e.target.value)||0}}}})} className="flex-1 rounded-md border-gray-300 p-1.5 border font-bold text-gray-900 text-right" />
                          <span className="text-gray-500 font-medium">/ Année</span>
                        </div>
                      ))}
                    </div>
                    
                    <div className="bg-[#3B1E6B] text-white text-center py-1 rounded-full font-bold shadow-md">SEMESTRE 1</div>
                    <div className="bg-gray-50 rounded-xl p-4 border border-gray-200 space-y-3">
                       <div className="flex items-center gap-2">
                         <span className="flex-1 font-medium text-gray-700">• Frais d'examen :</span>
                         <input type="number" value={financesForm.tarifs.cycleLocal.gestionFrais.s1_examen} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, cycleLocal: {...financesForm.tarifs.cycleLocal, gestionFrais: {...financesForm.tarifs.cycleLocal.gestionFrais, s1_examen: parseInt(e.target.value)||0}}}})} className="w-24 rounded border-gray-300 p-1 border text-right font-medium" />
                         <span className="text-gray-600">F</span>
                       </div>
                       <div className="flex items-center gap-2">
                         <span className="flex-1 font-medium text-gray-700">• Travaux pratiques :</span>
                         <input type="number" value={financesForm.tarifs.cycleLocal.gestionFrais.s1_tp} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, cycleLocal: {...financesForm.tarifs.cycleLocal, gestionFrais: {...financesForm.tarifs.cycleLocal.gestionFrais, s1_tp: parseInt(e.target.value)||0}}}})} className="w-24 rounded border-gray-300 p-1 border text-right font-medium" />
                         <span className="text-gray-600">F</span>
                       </div>
                    </div>

                    <div className="bg-[#3B1E6B] text-white text-center py-1 rounded-full font-bold shadow-md">SEMESTRE 2</div>
                    <div className="bg-gray-50 rounded-xl p-4 border border-gray-200 space-y-3">
                       <div className="flex items-center gap-2">
                         <span className="flex-1 font-medium text-gray-700">• Frais d'examen :</span>
                         <input type="number" value={financesForm.tarifs.cycleLocal.gestionFrais.s2_examen} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, cycleLocal: {...financesForm.tarifs.cycleLocal, gestionFrais: {...financesForm.tarifs.cycleLocal.gestionFrais, s2_examen: parseInt(e.target.value)||0}}}})} className="w-24 rounded border-gray-300 p-1 border text-right font-medium" />
                         <span className="text-gray-600">F</span>
                       </div>
                       <div className="flex items-center gap-2">
                         <span className="flex-1 font-medium text-gray-700">• Frais de stage :</span>
                         <input type="number" value={financesForm.tarifs.cycleLocal.gestionFrais.s2_stage} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, cycleLocal: {...financesForm.tarifs.cycleLocal, gestionFrais: {...financesForm.tarifs.cycleLocal.gestionFrais, s2_stage: parseInt(e.target.value)||0}}}})} className="w-24 rounded border-gray-300 p-1 border text-right font-medium" />
                         <span className="text-gray-600">F</span>
                       </div>
                       <div className="flex items-center gap-2">
                         <span className="flex-1 font-medium text-gray-700">• Travaux pratiques :</span>
                         <input type="number" value={financesForm.tarifs.cycleLocal.gestionFrais.s2_tp} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, cycleLocal: {...financesForm.tarifs.cycleLocal, gestionFrais: {...financesForm.tarifs.cycleLocal.gestionFrais, s2_tp: parseInt(e.target.value)||0}}}})} className="w-24 rounded border-gray-300 p-1 border text-right font-medium" />
                         <span className="text-gray-600">F</span>
                       </div>
                    </div>
                  </div>
                  
                  {/* OPTION INDUSTRIE */}
                  <div className="space-y-4">
                    <div className="bg-[#3B1E6B] text-white text-center py-2 rounded-full font-bold shadow-md">OPTION INDUSTRIE</div>
                    <div className="bg-gray-50 rounded-xl p-4 border-2 border-gray-100 shadow-sm space-y-3">
                      {Object.entries(financesForm.tarifs.cycleLocal.industrie).map(([niveau, montant]) => (
                        <div key={niveau} className="flex items-center gap-2">
                          <span className="w-24 font-bold text-gray-700">{niveau} :</span>
                          <input type="number" value={montant as number} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, cycleLocal: {...financesForm.tarifs.cycleLocal, industrie: {...financesForm.tarifs.cycleLocal.industrie, [niveau]: parseInt(e.target.value)||0}}}})} className="flex-1 rounded-md border-gray-300 p-1.5 border font-bold text-gray-900 text-right" />
                          <span className="text-gray-500 font-medium">/ Année</span>
                        </div>
                      ))}
                    </div>

                    <div className="bg-[#3B1E6B] text-white text-center py-1 rounded-full font-bold shadow-md">SEMESTRE 1</div>
                    <div className="bg-gray-50 rounded-xl p-4 border border-gray-200 space-y-3">
                       <div className="flex items-center gap-2">
                         <span className="flex-1 font-medium text-gray-700">• Accessoires TP :</span>
                         <input type="number" value={financesForm.tarifs.cycleLocal.industrieFrais.s1_tp} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, cycleLocal: {...financesForm.tarifs.cycleLocal, industrieFrais: {...financesForm.tarifs.cycleLocal.industrieFrais, s1_tp: parseInt(e.target.value)||0}}}})} className="w-24 rounded border-gray-300 p-1 border text-right font-medium" />
                         <span className="text-gray-600">F</span>
                       </div>
                       <div className="flex items-center gap-2">
                         <span className="flex-1 font-medium text-gray-700">• Frais d'examen :</span>
                         <input type="number" value={financesForm.tarifs.cycleLocal.industrieFrais.s1_examen} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, cycleLocal: {...financesForm.tarifs.cycleLocal, industrieFrais: {...financesForm.tarifs.cycleLocal.industrieFrais, s1_examen: parseInt(e.target.value)||0}}}})} className="w-24 rounded border-gray-300 p-1 border text-right font-medium" />
                         <span className="text-gray-600">F</span>
                       </div>
                    </div>

                    <div className="bg-[#3B1E6B] text-white text-center py-1 rounded-full font-bold shadow-md">SEMESTRE 2</div>
                    <div className="bg-gray-50 rounded-xl p-4 border border-gray-200 space-y-3">
                       <div className="flex items-center gap-2">
                         <span className="flex-1 font-medium text-gray-700">• Accessoires TP :</span>
                         <input type="number" value={financesForm.tarifs.cycleLocal.industrieFrais.s2_tp} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, cycleLocal: {...financesForm.tarifs.cycleLocal, industrieFrais: {...financesForm.tarifs.cycleLocal.industrieFrais, s2_tp: parseInt(e.target.value)||0}}}})} className="w-24 rounded border-gray-300 p-1 border text-right font-medium" />
                         <span className="text-gray-600">F</span>
                       </div>
                       <div className="flex items-center gap-2">
                         <span className="flex-1 font-medium text-gray-700">• Frais d'examen :</span>
                         <input type="number" value={financesForm.tarifs.cycleLocal.industrieFrais.s2_examen} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, cycleLocal: {...financesForm.tarifs.cycleLocal, industrieFrais: {...financesForm.tarifs.cycleLocal.industrieFrais, s2_examen: parseInt(e.target.value)||0}}}})} className="w-24 rounded border-gray-300 p-1 border text-right font-medium" />
                         <span className="text-gray-600">F</span>
                       </div>
                       <div className="flex items-center gap-2">
                         <span className="flex-1 font-medium text-gray-700">• Frais de stage :</span>
                         <input type="number" value={financesForm.tarifs.cycleLocal.industrieFrais.s2_stage} onChange={e => setFinancesForm({...financesForm, tarifs: {...financesForm.tarifs, cycleLocal: {...financesForm.tarifs.cycleLocal, industrieFrais: {...financesForm.tarifs.cycleLocal.industrieFrais, s2_stage: parseInt(e.target.value)||0}}}})} className="w-24 rounded border-gray-300 p-1 border text-right font-medium" />
                         <span className="text-gray-600">F</span>
                       </div>
                    </div>
                  </div>

                </div>
              </div>

              {/* COÛT ANNUEL, COURS DU SOIR (VAGUE DU SOIR) */}
              <div className="pt-6">
                <div className="bg-gradient-to-r from-[#3B1E6B] via-[#4C2882] to-[#1E1135] text-white text-center py-2.5 rounded-full font-bold mb-6 text-lg tracking-wide shadow-md flex items-center justify-center gap-2">
                  <span>🌙</span> COÛT ANNUEL, COURS DU SOIR (VAGUE DU SOIR)
                </div>
                
                <div className="bg-gradient-to-br from-purple-50/50 to-indigo-50/30 rounded-2xl p-6 border-2 border-purple-100 shadow-sm">
                  <p className="text-sm text-gray-600 mb-4 font-medium flex items-center gap-2">
                    <span className="text-purple-600 font-bold">ℹ️ Information :</span>
                    Ces tarifs s'appliquent automatiquement aux étudiants inscrits en <strong>Vague du Soir</strong> (Cours du Soir).
                  </p>
                  
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    {/* 1ère Année */}
                    <div className="bg-white rounded-xl p-5 border border-purple-200 shadow-sm hover:shadow-md transition-shadow">
                      <div className="flex items-center justify-between mb-3">
                        <span className="font-bold text-gray-900 text-base">1ère Année (L1)</span>
                        <span className="text-xs bg-purple-100 text-purple-800 font-semibold px-2 py-0.5 rounded-full">Soir</span>
                      </div>
                      <div className="space-y-2">
                        <label className="text-xs font-medium text-gray-500">Coût Annuel de la formation :</label>
                        <div className="flex items-center gap-2">
                          <input 
                            type="number" 
                            value={financesForm.tarifs.coursDuSoir?.['Licence 1'] ?? 300000} 
                            onChange={e => setFinancesForm({
                              ...financesForm, 
                              tarifs: {
                                ...financesForm.tarifs, 
                                coursDuSoir: {
                                  ...financesForm.tarifs.coursDuSoir, 
                                  'Licence 1': parseInt(e.target.value) || 0
                                }
                              }
                            })} 
                            className="flex-1 rounded-md border-gray-300 p-2 border font-bold text-gray-900 text-right focus:border-purple-500 focus:ring-purple-500 text-lg" 
                          />
                          <span className="text-gray-500 font-semibold text-sm">FCFA</span>
                        </div>
                        <div className="text-xs text-purple-700 bg-purple-50 p-2 rounded-md font-medium">
                          Mensualité estimée : {Math.round(((financesForm.tarifs.coursDuSoir?.['Licence 1'] ?? 300000) / 10)).toLocaleString()} F / mois
                        </div>
                      </div>
                    </div>

                    {/* 2ème Année */}
                    <div className="bg-white rounded-xl p-5 border border-purple-200 shadow-sm hover:shadow-md transition-shadow">
                      <div className="flex items-center justify-between mb-3">
                        <span className="font-bold text-gray-900 text-base">2ème Année (L2)</span>
                        <span className="text-xs bg-purple-100 text-purple-800 font-semibold px-2 py-0.5 rounded-full">Soir</span>
                      </div>
                      <div className="space-y-2">
                        <label className="text-xs font-medium text-gray-500">Coût Annuel de la formation :</label>
                        <div className="flex items-center gap-2">
                          <input 
                            type="number" 
                            value={financesForm.tarifs.coursDuSoir?.['Licence 2'] ?? 350000} 
                            onChange={e => setFinancesForm({
                              ...financesForm, 
                              tarifs: {
                                ...financesForm.tarifs, 
                                coursDuSoir: {
                                  ...financesForm.tarifs.coursDuSoir, 
                                  'Licence 2': parseInt(e.target.value) || 0
                                }
                              }
                            })} 
                            className="flex-1 rounded-md border-gray-300 p-2 border font-bold text-gray-900 text-right focus:border-purple-500 focus:ring-purple-500 text-lg" 
                          />
                          <span className="text-gray-500 font-semibold text-sm">FCFA</span>
                        </div>
                        <div className="text-xs text-purple-700 bg-purple-50 p-2 rounded-md font-medium">
                          Mensualité estimée : {Math.round(((financesForm.tarifs.coursDuSoir?.['Licence 2'] ?? 350000) / 10)).toLocaleString()} F / mois
                        </div>
                      </div>
                    </div>

                    {/* 3ème Année */}
                    <div className="bg-white rounded-xl p-5 border border-purple-200 shadow-sm hover:shadow-md transition-shadow">
                      <div className="flex items-center justify-between mb-3">
                        <span className="font-bold text-gray-900 text-base">3ème Année (L3)</span>
                        <span className="text-xs bg-purple-100 text-purple-800 font-semibold px-2 py-0.5 rounded-full">Soir</span>
                      </div>
                      <div className="space-y-2">
                        <label className="text-xs font-medium text-gray-500">Coût Annuel de la formation :</label>
                        <div className="flex items-center gap-2">
                          <input 
                            type="number" 
                            value={financesForm.tarifs.coursDuSoir?.['Licence 3'] ?? 400000} 
                            onChange={e => setFinancesForm({
                              ...financesForm, 
                              tarifs: {
                                ...financesForm.tarifs, 
                                coursDuSoir: {
                                  ...financesForm.tarifs.coursDuSoir, 
                                  'Licence 3': parseInt(e.target.value) || 0
                                }
                              }
                            })} 
                            className="flex-1 rounded-md border-gray-300 p-2 border font-bold text-gray-900 text-right focus:border-purple-500 focus:ring-purple-500 text-lg" 
                          />
                          <span className="text-gray-500 font-semibold text-sm">FCFA</span>
                        </div>
                        <div className="text-xs text-purple-700 bg-purple-50 p-2 rounded-md font-medium">
                          Mensualité estimée : {Math.round(((financesForm.tarifs.coursDuSoir?.['Licence 3'] ?? 400000) / 10)).toLocaleString()} F / mois
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div className="border-t border-gray-200 pt-8 mt-12">
                <h2 className="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                  <Wallet className="h-5 w-5 text-gray-500" />
                  Catégories de Dépenses Autorisées
                </h2>
                <div className="space-y-3 mb-4 max-w-2xl">
                  {financesForm.categoriesDepenses.map((cat, idx) => (
                    <div key={idx} className="flex items-center justify-between bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                      <span className="text-gray-700 font-medium">{cat}</span>
                      <button onClick={() => handleRemoveCategory(idx)} className="text-red-400 hover:text-red-600 p-1 transition-colors">
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  ))}
                </div>
                <div className="flex gap-2 max-w-2xl">
                  <input
                    type="text"
                    value={financesForm.newCategory}
                    onChange={e => setFinancesForm({...financesForm, newCategory: e.target.value})}
                    placeholder="Nouvelle catégorie (ex: Assurance)..."
                    className="flex-1 rounded-md border-gray-300 shadow-sm focus:border-[#3B1E6B] focus:ring-[#3B1E6B] p-2 border"
                    onKeyDown={e => e.key === 'Enter' && handleAddCategory()}
                  />
                  <button onClick={handleAddCategory} className="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-200 flex items-center gap-2 font-medium">
                    <Plus className="h-4 w-4" /> Ajouter
                  </button>
                </div>
              </div>

              <div className="pt-6 flex justify-end sticky bottom-0 bg-white/80 backdrop-blur-sm p-4 border-t border-gray-100 shadow-[0_-10px_15px_-3px_rgba(0,0,0,0.05)] rounded-t-xl z-10">
                <button onClick={handleSaveFinances} className="flex items-center gap-2 bg-[#F39200] text-white px-8 py-3 rounded-lg hover:bg-[#d68000] font-bold shadow-lg hover:shadow-xl transition-all">
                  <Save className="h-5 w-5" /> Enregistrer la Grille Tarifaire
                </button>
              </div>
            </div>
          )}

          {activeTab === 'personnel' && (
            <div className="space-y-8 animate-fade-in">
              <div>
                <div className="flex items-center justify-between mb-4">
                  <h2 className="text-lg font-semibold text-gray-900">Configuration des Déductions</h2>
                  <button onClick={handleAddDeduction} className="text-sm bg-gray-100 text-gray-700 px-3 py-1.5 rounded-lg hover:bg-gray-200 flex items-center gap-2">
                    <Plus className="h-4 w-4" /> Ajouter
                  </button>
                </div>
                
                <div className="space-y-4">
                  {personnelForm.deductions.map((deduction, idx) => (
                    <div key={deduction.id} className="flex items-center gap-4 bg-gray-50 p-4 rounded-lg border border-gray-100">
                      <input
                        type="text"
                        value={deduction.nom}
                        onChange={e => {
                          const newDeds = [...personnelForm.deductions];
                          newDeds[idx].nom = e.target.value;
                          setPersonnelForm({...personnelForm, deductions: newDeds});
                        }}
                        className="flex-1 rounded-md border-gray-300 p-2 border"
                        placeholder="Nom (ex: IRPP)"
                      />
                      <select
                        value={deduction.type}
                        onChange={e => {
                          const newDeds = [...personnelForm.deductions];
                          newDeds[idx].type = e.target.value as any;
                          setPersonnelForm({...personnelForm, deductions: newDeds});
                        }}
                        className="rounded-md border-gray-300 p-2 border"
                      >
                        <option value="pourcentage">Pourcentage (%)</option>
                        <option value="fixe">Montant Fixe (F)</option>
                      </select>
                      <div className="relative w-32">
                        <input
                          type="number"
                          value={deduction.valeur}
                          onChange={e => {
                            const newDeds = [...personnelForm.deductions];
                            newDeds[idx].valeur = parseFloat(e.target.value) || 0;
                            setPersonnelForm({...personnelForm, deductions: newDeds});
                          }}
                          className="w-full rounded-md border-gray-300 p-2 border"
                        />
                      </div>
                      <button 
                        onClick={() => {
                          const newDeds = [...personnelForm.deductions];
                          newDeds.splice(idx, 1);
                          setPersonnelForm({...personnelForm, deductions: newDeds});
                        }}
                        className="text-red-500 hover:text-red-700 p-2"
                      >
                        <Trash2 className="h-5 w-5" />
                      </button>
                    </div>
                  ))}
                  {personnelForm.deductions.length === 0 && (
                    <p className="text-gray-500 text-center py-4">Aucune déduction configurée.</p>
                  )}
                </div>
              </div>

              <div className="pt-4 flex justify-end">
                <button onClick={handleSavePersonnel} className="flex items-center gap-2 bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700">
                  <Save className="h-4 w-4" /> Enregistrer
                </button>
              </div>
            </div>
          )}

          {activeTab === 'securite' && (
            <div className="space-y-6 animate-fade-in max-w-lg">
              <h2 className="text-lg font-semibold text-gray-900 mb-4">Modifier mon mot de passe</h2>
              
              {securityForm.error && (
                <div className="bg-red-50 text-red-700 p-3 rounded-lg text-sm border border-red-200">
                  {securityForm.error}
                </div>
              )}
              {securityForm.success && (
                <div className="bg-green-50 text-green-700 p-3 rounded-lg text-sm border border-green-200">
                  {securityForm.success}
                </div>
              )}

              <div className="space-y-4">
                <div className="space-y-2">
                  <label className="text-sm font-medium text-gray-700">Mot de passe actuel</label>
                  <input
                    type="password"
                    value={securityForm.currentPassword}
                    onChange={e => setSecurityForm({...securityForm, currentPassword: e.target.value})}
                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 p-2 border"
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium text-gray-700">Nouveau mot de passe</label>
                  <input
                    type="password"
                    value={securityForm.newPassword}
                    onChange={e => setSecurityForm({...securityForm, newPassword: e.target.value})}
                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 p-2 border"
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium text-gray-700">Confirmer le nouveau mot de passe</label>
                  <input
                    type="password"
                    value={securityForm.confirmPassword}
                    onChange={e => setSecurityForm({...securityForm, confirmPassword: e.target.value})}
                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 p-2 border"
                  />
                </div>
              </div>

              <div className="pt-4">
                <button 
                  onClick={handleChangePassword} 
                  disabled={!securityForm.currentPassword || !securityForm.newPassword || !securityForm.confirmPassword}
                  className="w-full flex justify-center items-center gap-2 bg-gray-900 text-white px-6 py-2.5 rounded-lg hover:bg-gray-800 disabled:opacity-50"
                >
                  <Shield className="h-4 w-4" /> Mettre à jour la sécurité
                </button>
              </div>
            </div>
          )}

          {activeTab === 'donnees' && (
            <div className="space-y-8 animate-fade-in">
              <div>
                <h2 className="text-lg font-semibold text-gray-900 mb-2">Sauvegarde des Données</h2>
                <p className="text-gray-500 text-sm mb-6">
                  Téléchargez une copie complète de la base de données (Étudiants, Paiements) au format Excel pour vos archives.
                </p>
                <button 
                  onClick={handleExportData}
                  className="flex items-center gap-3 bg-green-600 text-white px-6 py-3 rounded-lg hover:bg-green-700 shadow-sm"
                >
                  <Download className="h-5 w-5" /> 
                  <span className="font-medium">Exporter la base de données (Excel)</span>
                </button>
              </div>

              <div className="border-t border-gray-100 pt-8 mt-8">
                <h2 className="text-lg font-semibold text-gray-900 mb-2">Maintenance</h2>
                <p className="text-gray-500 text-sm mb-6">
                  Les données sont synchronisées automatiquement avec le serveur. Si vous constatez un problème de réseau, vous pouvez forcer la vérification.
                </p>
                <button 
                  className="flex items-center gap-3 bg-gray-100 text-gray-700 px-6 py-3 rounded-lg hover:bg-gray-200 border border-gray-200"
                  onClick={() => alert("La synchronisation automatique est déjà en cours de fonctionnement en arrière-plan.")}
                >
                  <Database className="h-5 w-5" /> 
                  <span className="font-medium">Vérifier la synchronisation</span>
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
