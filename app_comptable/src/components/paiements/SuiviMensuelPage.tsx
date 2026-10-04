import { useState, useMemo } from 'react';
import { Search, CalendarDays, CheckCircle, AlertTriangle, Clock, Download } from 'lucide-react';
import { db } from '../../db/db';
import { useLiveQuery } from 'dexie-react-hooks';
import { getFinancialStatus } from '../../utils/pricing';
import { exportToPDF } from '../../utils/pdfExport';

type TabType = 'SOLDES' | 'AVANCES' | 'DETTES';

export default function SuiviMensuelPage() {
  const [searchTerm, setSearchTerm] = useState('');
  const [activeTab, setActiveTab] = useState<TabType>('DETTES');

  const etudiants = useLiveQuery(() => db.etudiants.toArray()) || [];
  const paiements = useLiveQuery(() => db.paiements.toArray()) || [];

  // Categorize students
  const { soldes, avances, dettes } = useMemo(() => {
    const listSoldes: any[] = [];
    const listAvances: any[] = [];
    const listDettes: any[] = [];

    etudiants.forEach(etudiant => {
      // Calculate total paid for scolarite
      const totalScolaritePaye = paiements
        .filter(p => p.etudiant_id === etudiant.matricule && (!p.type_paiement || p.type_paiement === 'Frais Scolaire'))
        .reduce((sum, p) => sum + Number(p.montant || 0), 0);

      const status = getFinancialStatus(etudiant, totalScolaritePaye);
      const studentData = { etudiant, status };

      if (status.arrieres <= 0) {
        listSoldes.push(studentData);
      } else if (status.arrieres > 0 && status.arrieres < status.mensualite) {
        // They owe something, but less than a full month. Meaning they paid a partial amount for the current expected month.
        listAvances.push(studentData);
      } else {
        // They owe at least one full month (or more)
        listDettes.push(studentData);
      }
    });

    return { soldes: listSoldes, avances: listAvances, dettes: listDettes };
  }, [etudiants, paiements]);

  // Determine which list to show and apply search filter
  const currentList = useMemo(() => {
    let baseList = [];
    if (activeTab === 'SOLDES') baseList = soldes;
    else if (activeTab === 'AVANCES') baseList = avances;
    else baseList = dettes;

    if (!searchTerm) return baseList;
    
    const lowerSearch = searchTerm.toLowerCase();
    return baseList.filter(({ etudiant }) => 
      etudiant.nom.toLowerCase().includes(lowerSearch) ||
      etudiant.prenom.toLowerCase().includes(lowerSearch) ||
      etudiant.matricule.toLowerCase().includes(lowerSearch)
    );
  }, [activeTab, soldes, avances, dettes, searchTerm]);

  const handleExportPDF = () => {
    const columns = [
      { header: 'Matricule', dataKey: 'matricule' },
      { header: 'Nom et Prénom', dataKey: 'nom_complet' },
      { header: 'Niveau', dataKey: 'niveau' },
      { header: 'Total Payé (Scolarité)', dataKey: 'total_paye' },
      { header: 'Arriérés (Dettes)', dataKey: 'arrieres' },
    ];
    
    const formattedData = currentList.map(({ etudiant, status }) => ({
      matricule: etudiant.matricule,
      nom_complet: `${etudiant.nom} ${etudiant.prenom}`,
      niveau: etudiant.niveau,
      total_paye: `${status.totalScolaritePaye.toLocaleString('fr-FR')} F`,
      arrieres: `${status.arrieres.toLocaleString('fr-FR')} F`,
    }));

    exportToPDF(
      `Suivi Mensuel - ${activeTab}`,
      columns,
      formattedData,
      `suivi_mensuel_${activeTab.toLowerCase()}`
    );
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-2xl font-bold text-on-surface flex items-center gap-2">
            <CalendarDays className="w-6 h-6 text-primary" />
            Suivi Mensuel
          </h2>
          <p className="text-on-surface-variant mt-1">
            Contrôle des paiements pour le mois en cours (classification automatique)
          </p>
        </div>
        <button 
          onClick={handleExportPDF}
          className="flex items-center gap-2 bg-surface-container text-on-surface border border-outline-variant px-4 py-2 rounded-full font-medium hover:bg-surface-container-highest transition-colors"
        >
          <Download className="w-5 h-5" />
          PDF
        </button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <button 
          onClick={() => setActiveTab('DETTES')}
          className={`p-4 rounded-2xl border transition-all text-left ${
            activeTab === 'DETTES' 
              ? 'bg-error-container border-error text-on-error-container shadow-md scale-[1.02]' 
              : 'bg-surface-container-lowest border-outline-variant hover:bg-error-container/20'
          }`}
        >
          <div className="flex justify-between items-center mb-2">
            <div className="font-bold flex items-center gap-2">
              <AlertTriangle className={`w-5 h-5 ${activeTab === 'DETTES' ? 'text-error' : 'text-on-surface-variant'}`} />
              Dettes (Non payé)
            </div>
            <div className="text-2xl font-black">{dettes.length}</div>
          </div>
          <div className="text-sm opacity-80">Retard d'un mois ou plus</div>
        </button>

        <button 
          onClick={() => setActiveTab('AVANCES')}
          className={`p-4 rounded-2xl border transition-all text-left ${
            activeTab === 'AVANCES' 
              ? 'bg-secondary-container border-secondary text-on-secondary-container shadow-md scale-[1.02]' 
              : 'bg-surface-container-lowest border-outline-variant hover:bg-secondary-container/20'
          }`}
        >
          <div className="flex justify-between items-center mb-2">
            <div className="font-bold flex items-center gap-2">
              <Clock className={`w-5 h-5 ${activeTab === 'AVANCES' ? 'text-secondary' : 'text-on-surface-variant'}`} />
              Avances (Partiel)
            </div>
            <div className="text-2xl font-black">{avances.length}</div>
          </div>
          <div className="text-sm opacity-80">Paiement partiel du mois en cours</div>
        </button>

        <button 
          onClick={() => setActiveTab('SOLDES')}
          className={`p-4 rounded-2xl border transition-all text-left ${
            activeTab === 'SOLDES' 
              ? 'bg-success-container border-success text-on-success-container shadow-md scale-[1.02]' 
              : 'bg-surface-container-lowest border-outline-variant hover:bg-success-container/20'
          }`}
        >
          <div className="flex justify-between items-center mb-2">
            <div className="font-bold flex items-center gap-2">
              <CheckCircle className={`w-5 h-5 ${activeTab === 'SOLDES' ? 'text-success' : 'text-on-surface-variant'}`} />
              Soldés (À jour)
            </div>
            <div className="text-2xl font-black">{soldes.length}</div>
          </div>
          <div className="text-sm opacity-80">Mois en cours validé ou payé d'avance</div>
        </button>
      </div>

      <div className="bg-surface-container-lowest border border-outline-variant rounded-2xl overflow-hidden shadow-sm">
        <div className="p-4 border-b border-outline-variant flex flex-col sm:flex-row gap-4 items-center justify-between bg-surface-container-lowest">
          <div className="relative w-full sm:w-96">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-on-surface-variant" />
            <input
              type="text"
              placeholder="Rechercher par nom, prénom ou matricule..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full pl-10 pr-4 py-2 bg-surface-container-low border border-outline-variant rounded-full text-on-surface focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all"
            />
          </div>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="border-b border-outline-variant bg-surface-container-lowest">
                <th className="p-4 font-semibold text-sm text-on-surface-variant">Étudiant</th>
                <th className="p-4 font-semibold text-sm text-on-surface-variant">Mensualité</th>
                <th className="p-4 font-semibold text-sm text-on-surface-variant">Total Payé</th>
                <th className="p-4 font-semibold text-sm text-on-surface-variant">Mois attendus</th>
                <th className="p-4 font-semibold text-sm text-on-surface-variant text-right">Reste / Arriérés</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-outline-variant/50">
              {currentList.length === 0 ? (
                <tr>
                  <td colSpan={5} className="p-8 text-center text-on-surface-variant">
                    Aucun étudiant dans cette catégorie.
                  </td>
                </tr>
              ) : (
                currentList.map(({ etudiant, status }) => (
                  <tr key={etudiant.id} className="hover:bg-surface-container-low/50 transition-colors">
                    <td className="p-4 font-medium text-on-surface">
                      <div className="flex flex-col">
                        <span>{etudiant.nom} {etudiant.prenom}</span>
                        <span className="text-xs text-on-surface-variant font-mono">{etudiant.matricule} • {etudiant.niveau}</span>
                      </div>
                    </td>
                    <td className="p-4 text-sm text-on-surface-variant">
                      {status.mensualite.toLocaleString('fr-FR')} F
                    </td>
                    <td className="p-4 font-medium">
                      {status.totalScolaritePaye.toLocaleString('fr-FR')} F
                    </td>
                    <td className="p-4 text-sm text-on-surface-variant">
                      <span className="bg-surface-variant px-2 py-1 rounded-md text-on-surface">
                        Mois {status.expectedMonths}
                      </span>
                    </td>
                    <td className="p-4 text-right">
                      {status.arrieres > 0 ? (
                        <div className="flex flex-col items-end">
                          <span className={`font-bold text-lg ${activeTab === 'AVANCES' ? 'text-secondary' : 'text-error'}`}>
                            {status.arrieres.toLocaleString('fr-FR')} F
                          </span>
                          <span className="text-[10px] text-on-surface-variant uppercase tracking-wider">à payer</span>
                        </div>
                      ) : (
                        <span className="inline-flex items-center gap-1 text-success font-bold bg-success-container/30 px-3 py-1 rounded-full text-sm">
                          <CheckCircle className="w-4 h-4" />
                          À jour
                        </span>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
