const xlsx = require('xlsx');
const path = require('path');
const fs = require('fs');
const crypto = require('crypto');

const workbook = xlsx.readFile(path.join(__dirname, '../MES LICENCE 1 2026-2027.xlsx'));
const sheetName = workbook.SheetNames[0];
const sheet = workbook.Sheets[sheetName];
// Get data as an array of arrays
const data = xlsx.utils.sheet_to_json(sheet, { header: 1 });

// Assume row 0 or 1 is header, let's find the header row by looking for "NOM (S)"
let headerIndex = -1;
for (let i = 0; i < 5; i++) {
  if (data[i] && data[i].includes("NOM (S)")) {
    headerIndex = i;
    break;
  }
}

if (headerIndex === -1) {
  console.error("Could not find header row");
  process.exit(1);
}

const headers = data[headerIndex];
const cleanData = [];

for (let i = headerIndex + 1; i < data.length; i++) {
  const row = data[i];
  // Map row array to object using headers
  const rowObj = {};
  for (let j = 0; j < headers.length; j++) {
    if (headers[j]) {
      rowObj[headers[j]] = row[j];
    }
  }

  if (!rowObj["NOM (S)"] || !rowObj["PRENOM (S)"]) continue;

  const count = cleanData.length + 1;
  const newMatricule = rowObj["NUMERO MATRICULE"] || `ISGI-2627-${count.toString().padStart(4, '0')}`;
  
  let birthDate = "";
  if (rowObj["DATE DE NAISSANCE"]) {
    if (typeof rowObj["DATE DE NAISSANCE"] === 'number') {
      birthDate = new Date((rowObj["DATE DE NAISSANCE"] - (25567 + 2)) * 86400 * 1000).toISOString().split('T')[0];
    } else {
      birthDate = rowObj["DATE DE NAISSANCE"].toString();
    }
  }

  cleanData.push({
    id: crypto.randomUUID(),
    matricule: newMatricule,
    nom: rowObj["NOM (S)"] || "",
    prenom: rowObj["PRENOM (S)"] || "",
    sexe: rowObj["SEXE"] || "",
    date_naissance: birthDate,
    lieu_naissance: rowObj["LIEU"] || "",
    nationalite: rowObj["NATIONALITE"] || "CONGOLAISE",
    adresse: rowObj["ADRESSE DE L'ETUDIANT"] || "",
    ville: "Brazzaville",
    pays: "Congo",
    telephone: rowObj["TELEPHONE"] || "",
    email: rowObj["E-MAIL"] || "",
    numero_cni: "",
    profession: rowObj["PROFESSION"] || "Etudiant",
    situation_matrimoniale: "Célibataire",
    nom_pere: rowObj["NOM ET PRENOM DU PÈRE"] || "",
    profession_pere: "",
    nom_mere: rowObj["NOM ET PRENOM DE LA MERE"] || "",
    profession_mere: "",
    nom_tuteur: rowObj["INFORMATION DU TUTEUR"] || "",
    profession_tuteur: rowObj["PROFESSION DU TUTEUR"] || "",
    telephone_tuteur: rowObj["TELEPHONE4"] || rowObj["TELEPHONE3"] || rowObj["TELEPHONE2"] || "",
    lieu_service_tuteur: "",
    type_etudiant: rowObj["TYPE ETUDIANT"] || "NORMAL",
    serie_bac: rowObj["SERIE DU BAC"] || "",
    filiere: rowObj["FILIERE"] || "",
    niveau: rowObj["NIVEAU D'ETUDE"] || "",
    cycle_formation: rowObj["CYCLE"] || "",
    rentree: rowObj["RENTREE"] || "OCTOBRE",
    site_formation: rowObj["ETABLISSEMENT"] || "ISGI",
    is_synced: 0,
    last_modified_at: new Date().toISOString(),
    documents_physiques: JSON.stringify({
      acte_naissance: false,
      attestation_bac: false,
      photos: false,
      certificat_medical: false
    })
  });
}

fs.writeFileSync(
  path.join(__dirname, 'src/db/seed.json'),
  JSON.stringify(cleanData, null, 2)
);

console.log(`Generated seed.json with ${cleanData.length} clean records!`);
