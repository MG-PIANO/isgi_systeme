require('dotenv').config();
const { createClient } = require('@supabase/supabase-js');
const { v4: uuidv4 } = require('uuid');

const supabaseUrl = 'https://vbdhmgrysrerlmgumafx.supabase.co';
const supabaseKey = 'sb_publishable_sqJUSK-p5mF2Acy_bhxhAQ_nt_t6Fax';

const supabase = createClient(supabaseUrl, supabaseKey);

const filieres = ["Informatique", "Gestion des Ressources Humaines", "Logistique et Transport"];
const niveaux = ["Licence 1", "Licence 2", "Licence 3", "Master 1", "Master 2"];
const options = ["Cours du Jour", "Cours du Soir"];
const sexes = ["M", "F"];

const firstNamesM = ["Jean", "Paul", "Marc", "Luc", "Pierre", "Kevin", "David", "Christian", "Alain", "Bernard", "Charles", "Daniel"];
const firstNamesF = ["Marie", "Sophie", "Claire", "Julie", "Alice", "Celine", "Sarah", "Emilie", "Nathalie", "Isabelle", "Amina", "Fatou"];
const lastNames = ["Dupont", "Martin", "Kouassi", "Diallo", "Traore", "Ndiaye", "Sow", "Fall", "Diop", "Cisse", "Keita", "Toure"];

const generateStudents = () => {
  const students = [];
  let matriculeCounter = 2026001;

  filieres.forEach(filiere => {
    niveaux.forEach(niveau => {
      options.forEach(option => {
        // Generate at least 3 students per combination (Filiere x Niveau x Option)
        // Actually, there are 3x5x2 = 30 combinations. 30 * 3 = 90 students.
        // Let's just generate a nice mix to get around 20-30 total.
      });
    });
  });

  // Let's just randomly generate 60 students to ensure a good mix
  for (let i = 0; i < 60; i++) {
    const sexe = sexes[Math.floor(Math.random() * sexes.length)];
    const prenom = sexe === "M" ? firstNamesM[Math.floor(Math.random() * firstNamesM.length)] : firstNamesF[Math.floor(Math.random() * firstNamesF.length)];
    const nom = lastNames[Math.floor(Math.random() * lastNames.length)];
    const filiere = filieres[Math.floor(Math.random() * filieres.length)];
    const niveau = niveaux[Math.floor(Math.random() * niveaux.length)];
    const option = options[Math.floor(Math.random() * options.length)];
    
    students.push({
      id: uuidv4(),
      matricule: `MAT-${matriculeCounter++}`,
      nom: nom,
      prenom: prenom,
      sexe: sexe,
      date_naissance: `200${Math.floor(Math.random() * 5)}-0${Math.floor(Math.random() * 9) + 1}-1${Math.floor(Math.random() * 9)}`,
      lieu_naissance: "Brazzaville",
      nationalite: "Congolaise",
      adresse: "Rue de la paix, Brazzaville",
      ville: "Brazzaville",
      pays: "Congo",
      telephone: `+242 06 ${Math.floor(Math.random() * 9000000) + 1000000}`,
      email: `${prenom.toLowerCase()}.${nom.toLowerCase()}@example.com`,
      type_etudiant: "Nouveau",
      option: option,
      filiere: filiere,
      niveau: niveau,
      cycle_formation: niveau.includes("Master") ? "Master" : "Licence",
      annee_academique: "2026-2027"
    });
  }

  return students;
};

async function seedEtudiants() {
  console.log("Generating students...");
  const students = generateStudents();
  console.log(`Inserting ${students.length} students...`);
  
  const { data, error } = await supabase
    .from('etudiants')
    .insert(students);
    
  if (error) {
    console.error("Error inserting students:", error);
  } else {
    console.log("Successfully inserted students!");
  }
}

seedEtudiants();
