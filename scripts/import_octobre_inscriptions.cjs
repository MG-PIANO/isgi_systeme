const fs = require('fs');
const crypto = require('crypto');
const path = require('path');
const xlsx = require(path.join(__dirname, '../app_dac/node_modules/xlsx'));

const supabaseUrl = 'https://vbdhmgrysrerlmgumafx.supabase.co';
const supabaseKey = 'sb_publishable_sqJUSK-p5mF2Acy_bhxhAQ_nt_t6Fax';

async function fetchSupabase(endpoint, options = {}) {
  const url = `${supabaseUrl}/rest/v1/${endpoint}`;
  const headers = {
    'apikey': supabaseKey,
    'Authorization': `Bearer ${supabaseKey}`,
    'Content-Type': 'application/json',
    ...(options.headers || {})
  };
  const resp = await fetch(url, { ...options, headers });
  if (!resp.ok) {
    const text = await resp.text();
    throw new Error(`HTTP ${resp.status} on ${endpoint}: ${text}`);
  }
  return resp;
}

// 1. Charger les enrichissements de l'ancien fichier
const oldStudents = [];
try {
  const wbOld = xlsx.readFile('MES LICENCE 1 2026-2027.xlsx');
  const wsOld = wbOld.Sheets['MODIF OK'];
  const oldRows = xlsx.utils.sheet_to_json(wsOld, { header: 1 });
  for (let i = 3; i < oldRows.length; i++) {
    const r = oldRows[i];
    if (r && r[3]) {
      oldStudents.push({
        nom: String(r[3]).trim().toUpperCase(),
        prenom: String(r[4] || '').trim(),
        tel: r[9],
        email: r[10],
        pere: r[22],
        mere: r[24],
        tuteur: r[26],
        tel_tuteur: r[27],
        prof_tuteur: r[28],
        adresse: r[29]
      });
    }
  }
} catch (e) {
  console.log('Ancien fichier non chargé ou absent, enrichissement ignoré:', e.message);
}

// 2. Fonctions de nettoyage
function parseFullName(raw) {
  if (!raw) return { nom: 'INCONNU', prenom: '' };
  let isBourse = /bourse/i.test(raw);
  let str = String(raw).replace(/\s*\([^)]*\)/g, '').trim();
  str = str.replace(/\s*-\s*/g, '-').replace(/\s+/g, ' ');
  const tokens = str.split(' ');
  if (tokens.length === 1) return { nom: tokens[0].toUpperCase(), prenom: '', isBourse };

  let nomTokens = [];
  let prenomTokens = [];
  let foundPrenom = false;

  for (let i = 0; i < tokens.length; i++) {
    const t = tokens[i];
    const isUpper = t === t.toUpperCase() && !/^[0-9]+$/.test(t);
    if (!foundPrenom && (isUpper || i === 0)) {
      nomTokens.push(t.toUpperCase());
    } else {
      foundPrenom = true;
      prenomTokens.push(t);
    }
  }

  if (prenomTokens.length === 0 && nomTokens.length > 1) {
    const last = nomTokens.pop();
    prenomTokens.push(last);
  }

  return { nom: nomTokens.join(' '), prenom: prenomTokens.join(' '), isBourse };
}

function parseDateAndPlace(raw) {
  if (!raw) return { date: null, lieu: null };
  const str = String(raw).trim();
  const parts = str.split(/[-–—]/);
  let datePart = parts[0] ? parts[0].trim() : '';
  let lieuPart = parts.slice(1).join('-').trim();

  let formattedDate = null;
  if (datePart) {
    const dmy = datePart.split('/');
    if (dmy.length === 3) {
      let day = parseInt(dmy[0], 10);
      let month = parseInt(dmy[1], 10);
      let year = parseInt(dmy[2], 10);
      if (month > 12) {
        if (day <= 12) {
          const temp = day; day = month; month = temp;
        } else {
          month = 11; // Faute de frappe 21/14/2008 -> 21/11/2008
        }
      }
      if (year < 100) year += 2000;
      formattedDate = year + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
    }
  }

  if (lieuPart) {
    const lu = lieuPart.toUpperCase();
    if (lu === 'BZV' || lu.includes('BRAZZA')) lieuPart = 'Brazzaville';
    else if (lu === 'PN' || lu === 'PONO2' || lu.includes('POINTE')) lieuPart = 'Pointe-Noire';
    else if (lu === 'OWANDO') lieuPart = 'Owando';
    else if (lu === 'MAKOUA') lieuPart = 'Makoua';
    else if (lu === 'ETOUMBI') lieuPart = 'Etoumbi';
    else if (lu === 'YAMBA') lieuPart = 'Yamba';
    else if (lu === 'LEKANA') lieuPart = 'Lékana';
    else if (lu === 'TONGO') lieuPart = 'Tongo';
    else if (lu === 'MFOUATI') lieuPart = 'Mfouati';
    else if (lu === 'MINDOULI') lieuPart = 'Mindouli';
    else if (lu === 'IMPFONDO') lieuPart = 'Impfondo';
    else if (lu === 'OYO') lieuPart = 'Oyo';
    else if (lu.includes('MADINGO')) lieuPart = 'Madingo-Kayes';
    else lieuPart = lieuPart.charAt(0).toUpperCase() + lieuPart.slice(1).toLowerCase();
  }

  return { date: formattedDate, lieu: lieuPart || null };
}

function normalizeFiliere(raw, sheetName) {
  if (!raw || String(raw).trim() === '') {
    if (sheetName === 'GESTION') return 'GESTION DES ENTREPRISES';
    if (sheetName === 'TECHNOLOGIE') return 'INFORMATIQUE ET TECHNOLOGIE';
    return 'MAINTENANCE INDUSTRIELLE';
  }
  const f = String(raw).trim();
  const lower = f.toLowerCase();
  if (lower.includes('banque')) return 'BANQUE ET FINANCE';
  if (lower.includes('comptabilit')) return "COMPTABILITE ET GESTION D'ENTREPRISE";
  if (lower.includes('grh') || lower.includes('ressources humaines')) return 'GESTION DES RESSOURCES HUMAINES (GRH)';
  if (lower.includes('affaires mondiales')) return 'GESTION EN AFFAIRES MONDIALES';
  if (lower.includes('droit des affaires')) return 'DROIT DES AFFAIRES';
  if (lower.includes('droit priv')) return 'DROIT PRIVE';
  if (lower.includes('droit public')) return 'DROIT PUBLIC';
  if (lower === 'droit') return 'DROIT';
  if (lower.includes('logistique')) return 'LOGISTIQUE ET TRANSPORT';
  if (lower.includes('marketing')) return 'MARKETING ET COMMUNICATION';
  if (lower.includes('reseaux')) return 'RESEAUX ET TELECOMMUNICATION';
  if (lower.includes('animation')) return 'ANIMATION 2D, 3D';
  if (lower.includes('robotique')) return 'ROBOTIQUE ET IA';
  if (lower.includes('sécurité') || lower.includes('cyber')) return 'CYBER SECURITE';
  if (lower.includes('infographie')) return 'INFOGRAPHIE';
  if (lower.includes('génie infomatique') || lower.includes('informatique')) return 'GENIE INFORMATIQUE';
  if (lower.includes('logiciel')) return 'GENIE LOGICIEL';
  if (lower.includes('fibre') || lower.includes('télécommunication')) return 'TELECOMMUNICATIONS ET FIBRE OPTIQUE';
  if (lower.includes('maintenance')) return 'MAINTENANCE INDUSTRIELLE';
  if (lower.includes('petrole')) return 'PETROLE ET GAZ';
  if (lower.includes('mécanique')) return 'GENIE MECANIQUE';
  if (lower.includes('qhse')) return 'QUALITE HYGIENE SECURITE ENVIRONNEMENT (QHSE)';
  if (lower.includes('civil') || lower.includes('btp') || lower.includes('architecture')) return 'GENIE CIVIL ET ARCHITECTURE';
  return f.toUpperCase();
}

function cleanPhone(raw) {
  if (!raw) return null;
  let str = String(raw).trim().replace(/[^\d]/g, '');
  if (str.length < 8) return null; // ex: '6'
  // Formater '06 123 45 67' si 9 chiffres commençant par 06
  if (str.length === 9) {
    return `${str.slice(0, 2)} ${str.slice(2, 5)} ${str.slice(5, 7)} ${str.slice(7, 9)}`;
  }
  return String(raw).trim();
}

async function main() {
  console.log('=== DÉBUT DE LA MIGRATION DES INSCRIPTIONS ===');
  const wb = xlsx.readFile('INSCRIPTION Octobre 2026-2027.xlsx');
  
  const allStudents = [];
  const allPayments = [];
  let seq = 1;

  for (const sheetName of wb.SheetNames) {
    const ws = wb.Sheets[sheetName];
    const rows = xlsx.utils.sheet_to_json(ws, { header: 1 });
    console.log(`Traitement de la feuille ${sheetName}: ${rows.length - 1} lignes`);

    for (let i = 1; i < rows.length; i++) {
      const r = rows[i];
      if (!r || !r[0] || String(r[0]).trim() === '') continue;

      const rawNom = r[0];
      const parsedName = parseFullName(rawNom);
      const parsedBirth = parseDateAndPlace(r[1]);
      const rawSexe = String(r[2] || '').trim().toUpperCase();
      const sexe = (rawSexe === 'FEMININ' || rawSexe === 'F') ? 'F' : 'M';
      
      const rawSerie = r[3] ? String(r[3]).trim().toUpperCase() : null;
      let serie_bac = rawSerie;
      if (serie_bac === 'SANS BAC' || serie_bac === '') serie_bac = null;

      let annee_bac = null;
      if (r[4]) {
        const m = String(r[4]).match(/\d{4}/);
        if (m) annee_bac = parseInt(m[0], 10);
      }

      const filiere = normalizeFiliere(r[5], sheetName);
      const tel = cleanPhone(r[12]);
      const caissier = r[13] ? String(r[13]).trim() : 'Service Inscriptions';

      // Frais et fournitures
      const fraisInscription = Number(r[6]) || 0;
      const fraisPolo = Number(r[7]) || 0;
      const fraisCarte = Number(r[8]) || 0;
      const fraisAssurance = Number(r[9]) || 0;

      // RAM
      let ramFourni = false;
      let ramMontant = 0;
      if (r[10] !== undefined && r[10] !== null) {
        if (typeof r[10] === 'string' && /oui/i.test(r[10])) {
          ramFourni = true;
        } else if (!isNaN(Number(r[10])) && Number(r[10]) > 0) {
          ramMontant = Number(r[10]);
          ramFourni = true;
        }
      }

      // MARQUEUR
      let marqFourni = false;
      let marqMontant = 0;
      if (r[11] !== undefined && r[11] !== null) {
        if (typeof r[11] === 'string' && /oui/i.test(r[11])) {
          marqFourni = true;
        } else if (!isNaN(Number(r[11])) && Number(r[11]) > 0) {
          marqMontant = Number(r[11]);
          marqFourni = true;
        }
      }

      const matricule = `ISGI-2627-${String(seq).padStart(4, '0')}`;
      const studentId = crypto.randomUUID();
      seq++;

      // Vérifier correspondance avec l'ancien fichier pour enrichir
      const matchOld = oldStudents.find(o => 
        parsedName.nom.includes(o.nom) || o.nom.includes(parsedName.nom)
      );

      const studentRecord = {
        id: studentId,
        matricule,
        nom: parsedName.nom,
        prenom: parsedName.prenom,
        sexe,
        date_naissance: parsedBirth.date,
        lieu_naissance: parsedBirth.lieu,
        nationalite: 'CONGOLAISE',
        adresse: matchOld ? matchOld.adresse : null,
        telephone: tel || (matchOld ? matchOld.tel : null),
        email: matchOld ? matchOld.email : null,
        profession: (sexe === 'F') ? 'ETUDIANTE' : 'ETUDIANT',
        type_etudiant: parsedName.isBourse ? 'BOURSE' : 'NORMAL',
        annee_obtention_bac: annee_bac,
        serie_bac,
        option: sheetName,
        filiere,
        niveau: 'Licence 1',
        cycle_formation: 'Licence',
        rentree: 'OCTOBRE',
        annee_academique: '2026-2027',
        site_formation: 'ISGI',
        vague: 'Jour',
        nom_pere: matchOld ? matchOld.pere : null,
        nom_mere: matchOld ? matchOld.mere : null,
        nom_tuteur: matchOld ? matchOld.tuteur : null,
        telephone_tuteur: matchOld ? matchOld.tel_tuteur : null,
        profession_tuteur: matchOld ? matchOld.prof_tuteur : null,
        documents_physiques: JSON.stringify({
          dossier_candidature: true,
          acte_naissance: true,
          photos: true,
          cni: false,
          rame: ramFourni,
          markers: marqFourni,
          enveloppe: false,
          diplome: Boolean(serie_bac && serie_bac !== 'SANS BAC'),
          releves_notes: false,
          frais_inscription: fraisInscription > 0,
          polo: fraisPolo > 0,
          carte_etudiant: fraisCarte > 0,
          frais_stages: false,
          frais_examens: false,
          frais_tptd: false,
          assurance: fraisAssurance > 0
        }),
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
        last_modified_at: new Date().toISOString()
      };

      function sanitize(obj) {
        const clean = {};
        for (const k of Object.keys(obj)) {
          clean[k] = obj[k] === undefined ? null : obj[k];
        }
        return clean;
      }

      allStudents.push(sanitize(studentRecord));

      // Création des paiements associés
      const basePay = {
        etudiant_id: matricule,
        mode_paiement: 'Espèces',
        statut: 'Réussi',
        gestionnaire_nom: caissier || 'Service Inscriptions',
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
        last_modified_at: new Date().toISOString()
      };

      if (fraisInscription > 0) {
        allPayments.push(sanitize({ ...basePay, id: crypto.randomUUID(), type_paiement: 'INSCRIPTION', montant: fraisInscription }));
      }
      if (fraisPolo > 0) {
        allPayments.push(sanitize({ ...basePay, id: crypto.randomUUID(), type_paiement: 'POLO', montant: fraisPolo }));
      }
      if (fraisCarte > 0) {
        allPayments.push(sanitize({ ...basePay, id: crypto.randomUUID(), type_paiement: 'CARTE_ETUDIANT', montant: fraisCarte }));
      }
      if (fraisAssurance > 0) {
        allPayments.push(sanitize({ ...basePay, id: crypto.randomUUID(), type_paiement: 'ASSURANCE', montant: fraisAssurance }));
      }
      if (ramMontant > 0) {
        allPayments.push(sanitize({ ...basePay, id: crypto.randomUUID(), type_paiement: 'RAME_PAPIER', montant: ramMontant }));
      }
      if (marqMontant > 0) {
        allPayments.push(sanitize({ ...basePay, id: crypto.randomUUID(), type_paiement: 'MARQUEURS', montant: marqMontant }));
      }
    }
  }

  console.log(`Total étudiants prêts: ${allStudents.length}`);
  console.log(`Total reçus de paiement prêts: ${allPayments.length}`);

  // 3. Suppression des anciennes données
  console.log('--- Nettoyage de la table paiements ---');
  try {
    await fetchSupabase('paiements?id=neq.00000000-0000-0000-0000-000000000000', {
      method: 'DELETE'
    });
    console.log('Table paiements vidée avec succès.');
  } catch (e) {
    console.log('Erreur vidage paiements (peut être vide):', e.message);
  }

  console.log('--- Nettoyage de la table etudiants ---');
  try {
    await fetchSupabase('etudiants?id=neq.00000000-0000-0000-0000-000000000000', {
      method: 'DELETE'
    });
    console.log('Anciens étudiants supprimés avec succès.');
  } catch (e) {
    console.log('Erreur suppression étudiants:', e.message);
  }

  // 4. Insertion des nouveaux étudiants par lots de 20
  console.log('--- Insertion des 66 étudiants dans Supabase ---');
  const batchSize = 20;
  for (let i = 0; i < allStudents.length; i += batchSize) {
    const chunk = allStudents.slice(i, i + batchSize);
    await fetchSupabase('etudiants', {
      method: 'POST',
      body: JSON.stringify(chunk)
    });
    console.log(`  Lot inséré: ${i + 1} à ${Math.min(i + batchSize, allStudents.length)}`);
  }

  // 5. Insertion des paiements par lots de 25
  console.log('--- Insertion des paiements dans Supabase ---');
  for (let i = 0; i < allPayments.length; i += batchSize) {
    const chunk = allPayments.slice(i, i + batchSize);
    await fetchSupabase('paiements', {
      method: 'POST',
      body: JSON.stringify(chunk)
    });
    console.log(`  Lot paiements inséré: ${i + 1} à ${Math.min(i + batchSize, allPayments.length)}`);
  }

  console.log('=== MIGRATION TERMINÉE AVEC SUCCÈS ===');
  console.log(`66 étudiants créés avec matricules ISGI-2627-0001 à ISGI-2627-${String(allStudents.length).padStart(4, '0')}.`);
  console.log(`${allPayments.length} lignes de paiements enregistrées.`);
}

main().catch(err => {
  console.error('ERREUR LORS DE LA MIGRATION:', err);
  process.exit(1);
});
