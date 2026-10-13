import { db, type Etudiant } from './db';
import { v4 as uuidv4 } from 'uuid';
import { supabase } from './supabaseClient';

export async function syncData(forcePull: boolean = false) {
  if (!navigator.onLine) return;

  try {
    // 1. PUSH: Get all local changes where is_synced = 0
    const pendingEtudiants = await db.etudiants.where('is_synced').equals(0).toArray();
    const pendingPaiements = await db.paiements.where('is_synced').equals(0).toArray();

    if (pendingEtudiants.length > 0) {
      let toPush = pendingEtudiants.map(({ is_synced, deleted_at, ...rest }: any) => {
        if (rest.id && rest.id.toString().length !== 36) {
          rest.id = uuidv4();
        }
        return rest;
      });
      
      toPush = toPush.filter((student: any, index: number, self: any[]) => 
        index === self.findIndex((s) => s.matricule === student.matricule)
      );

      const { error } = await supabase.from('etudiants').upsert(toPush, { onConflict: 'matricule' });
      if (!error) {
        for (const e of pendingEtudiants) {
          if (e.id) await db.etudiants.update(e.id, { is_synced: 1 });
        }
      } else {
        console.error("Error pushing etudiants to Supabase:", error);
      }
    }

    if (pendingPaiements.length > 0) {
      const toPush = pendingPaiements.map(({ is_synced, deleted_at, ...rest }: any) => {
        if (rest.montant) {
          rest.montant = Math.round(Number(rest.montant));
        }
        if (rest.id && rest.id.toString().length !== 36) {
          rest.id = uuidv4();
        }
        return rest;
      });
      const { error } = await supabase.from('paiements').upsert(toPush);
      if (!error) {
        for (const p of pendingPaiements) {
          if (p.id) await db.paiements.update(p.id, { is_synced: 1 });
        }
      } else {
        console.error("Error pushing paiements to Supabase:", error);
      }
    }

    // 2. PULL: Get latest changes from server
    let lastSyncTime = '2000-01-01T00:00:00.000Z';
    
    if (!forcePull) {
      const lastEtudiant = await db.etudiants.orderBy('last_modified_at').last();
      const lastPaiement = await db.paiements.orderBy('last_modified_at').last();
      if (lastEtudiant?.last_modified_at && lastEtudiant.last_modified_at > lastSyncTime) lastSyncTime = lastEtudiant.last_modified_at;
      if (lastPaiement?.last_modified_at && lastPaiement.last_modified_at > lastSyncTime) lastSyncTime = lastPaiement.last_modified_at;
    }

    if (forcePull) {
      await db.etudiants.where('is_synced').equals(1).delete();
      await db.paiements.where('is_synced').equals(1).delete();
    }

    // Pull Etudiants
    const { data: etudiantsData, error: etudiantsError } = forcePull 
      ? await supabase.from('etudiants').select('*')
      : await supabase.from('etudiants').select('*').gt('last_modified_at', lastSyncTime);

    if (etudiantsData && !etudiantsError) {
      for (const e of etudiantsData) {
        await db.etudiants.put({ ...e, is_synced: 1 });
      }
    }

    // Pull Paiements
    const { data: paiementsData, error: paiementsError } = forcePull
      ? await supabase.from('paiements').select('*')
      : await supabase.from('paiements').select('*').gt('last_modified_at', lastSyncTime);

    if (paiementsData && !paiementsError) {
      for (const p of paiementsData) {
        await db.paiements.put({ ...p, is_synced: 1 });
      }
    }

    // 3. PULL ACADÉMIQUE & MESSENGER (Pour consultation synchronisée avec le DAC)
    try {
      const { data: classesData } = await supabase.from('classes').select('*');
      if (classesData && classesData.length > 0) await db.classes.bulkPut(classesData);
    } catch {}

    try {
      const { data: matieresData } = await supabase.from('matieres').select('*');
      if (matieresData && matieresData.length > 0) await db.matieres.bulkPut(matieresData);
    } catch {}

    try {
      const { data: personnelData } = await supabase.from('personnel').select('*');
      if (personnelData && personnelData.length > 0) await db.personnel.bulkPut(personnelData);
    } catch {}

    try {
      const { data: sallesData } = await supabase.from('salles').select('*');
      if (sallesData && sallesData.length > 0) await db.salles.bulkPut(sallesData);
    } catch {}

    try {
      const { data: calData } = await supabase.from('calendriers_academiques').select('*');
      if (calData && calData.length > 0) await db.calendriers_academiques.bulkPut(calData);
    } catch {}

    try {
      const { data: edtData } = await supabase.from('emplois_du_temps').select('*');
      if (edtData && edtData.length > 0) await db.emplois_du_temps.bulkPut(edtData);
    } catch {}

    try {
      const { data: pubData } = await supabase.from('publications_academiques').select('*');
      if (pubData && pubData.length > 0) await db.publications_academiques.bulkPut(pubData);
    } catch {}

    try {
      const { data: convData } = await supabase.from('conversations').select('*');
      if (convData && convData.length > 0) await db.conversations.bulkPut(convData);
    } catch {}

    try {
      const { data: partData } = await supabase.from('conversation_participants').select('*');
      if (partData && partData.length > 0) await db.conversation_participants.bulkPut(partData);
    } catch {}

    try {
      const { data: msgData } = await supabase.from('messages').select('*');
      if (msgData && msgData.length > 0) await db.messages.bulkPut(msgData);
    } catch {}

    try {
      const { data: notifData } = await supabase.from('notifications').select('*');
      if (notifData && notifData.length > 0) await db.notifications.bulkPut(notifData);
    } catch {}

    try {
      const { data: presData } = await supabase.from('user_presences').select('*');
      if (presData && presData.length > 0) await db.user_presences.bulkPut(presData);
    } catch {}

  } catch (error) {
    console.error("Sync failed.", error);
  }
}

// Automatically sync when coming back online
export function autoSyncInit() {
  window.addEventListener('online', () => {
    console.log('App is back online. Starting auto-sync...');
    syncData();
  });
  
  if (navigator.onLine) {
    console.log('App started online. Synchronizing...');
    Promise.all([
      db.etudiants.toCollection().modify({ is_synced: 0 }),
      db.paiements.toCollection().modify({ is_synced: 0 })
    ]).then(() => {
      syncData(false);
    });
  }
}

export async function createEtudiantLocal(data: Omit<Etudiant, 'id' | 'is_synced' | 'last_modified_at'>) {
  const id = uuidv4();
  const etudiant: Etudiant = {
    ...data,
    id,
    is_synced: 0,
    last_modified_at: new Date().toISOString()
  };
  await db.etudiants.add(etudiant);
  syncData();
  return etudiant;
}
