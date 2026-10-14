import { createClient } from '@supabase/supabase-js';
import type { Student } from '../types/student';

export const SUPABASE_URL = 'https://vbdhmgrysrerlmgumafx.supabase.co';
export const SUPABASE_ANON_KEY = 'sb_publishable_sqJUSK-p5mF2Acy_bhxhAQ_nt_t6Fax';

export const supabase = createClient(SUPABASE_URL, SUPABASE_ANON_KEY);

const LOCAL_STORAGE_PHOTOS_KEY = 'isgi_studio_student_photos';
const LOCAL_STORAGE_STUDENTS_KEY = 'isgi_studio_students_cache';

// Load locally cached photos
export function getLocalStudentPhotos(): Record<string, string> {
  try {
    return JSON.parse(localStorage.getItem(LOCAL_STORAGE_PHOTOS_KEY) || '{}');
  } catch {
    return {};
  }
}

// Save photo to local cache
export function saveLocalStudentPhoto(studentKey: string, dataUrl: string): void {
  try {
    const photos = getLocalStudentPhotos();
    photos[studentKey] = dataUrl;
    localStorage.setItem(LOCAL_STORAGE_PHOTOS_KEY, JSON.stringify(photos));
  } catch (e) {
    console.warn('Erreur stockage cache local photo:', e);
  }
}

// Fetch all students and classes
export async function fetchStudentsFromSupabase(): Promise<Student[]> {
  const cachedPhotos = getLocalStudentPhotos();

  try {
    // 1. Fetch students & classes in parallel
    const studentsRes = await supabase.from('etudiants').select('*').order('nom', { ascending: true });
    let classesData: any[] = [];
    try {
      const classesRes = await supabase.from('classes').select('id, nom, filiere, niveau');
      if (classesRes?.data) classesData = classesRes.data;
    } catch {}

    if (studentsRes.error) {
      throw studentsRes.error;
    }

    const classesMap = new Map<string, { filiere?: string; niveau?: string }>();
    for (const c of classesData) {
      classesMap.set(String(c.id), c);
    }

    const students: Student[] = (studentsRes.data || []).map((raw: any) => {
      const cls = raw.classe_id ? classesMap.get(String(raw.classe_id)) : null;
      const idStr = String(raw.id);
      const mat = raw.matricule || '';
      
      const localPhoto = cachedPhotos[mat] || cachedPhotos[idStr] || null;

      return {
        id: idStr,
        nom: raw.nom || raw.last_name || '',
        prenom: raw.prenom || raw.first_name || '',
        matricule: mat,
        filiere: raw.filiere || cls?.filiere || '',
        niveau: raw.niveau || cls?.niveau || '',
        lieu_naissance: raw.lieu_naissance || raw.lieu || '',
        annee_academique: raw.annee_academique || '',
        photo_url: raw.photo_url || null,
        photo_cached: localPhoto,
        telephone: raw.telephone || raw.phone || null,
        email: raw.email || null,
      };
    });

    // Cache students list in localStorage for offline resiliency
    try {
      localStorage.setItem(LOCAL_STORAGE_STUDENTS_KEY, JSON.stringify(students));
    } catch {}

    return students;
  } catch (err) {
    console.warn('Erreur chargement Supabase, fallback cache local:', err);
    try {
      const cached = localStorage.getItem(LOCAL_STORAGE_STUDENTS_KEY);
      if (cached) return JSON.parse(cached);
    } catch {}
    throw err;
  }
}

// Resolve photo URL (signed or public)
export async function resolveStudentPhotoUrl(photoPath: string): Promise<string | null> {
  if (!photoPath) return null;
  if (photoPath.startsWith('data:') || photoPath.startsWith('blob:') || photoPath.startsWith('http')) {
    return photoPath;
  }

  try {
    // Try to get signed URL for 24h
    const { data, error } = await supabase.storage
      .from('student-photos')
      .createSignedUrl(photoPath, 86400);

    if (error || !data?.signedUrl) {
      // Try public url
      const { data: pubData } = supabase.storage.from('student-photos').getPublicUrl(photoPath);
      return pubData?.publicUrl || null;
    }

    return data.signedUrl;
  } catch (err) {
    console.warn(`Erreur résolution photo (${photoPath}):`, err);
    return null;
  }
}

// Upload photo to Supabase Storage and update student record
export async function uploadAndSaveStudentPhoto(
  student: Student,
  imageBlob: Blob,
  base64DataUrl: string
): Promise<{ success: boolean; photoPath: string; signedUrl: string }> {
  const nextPath = `photos/${student.id || student.matricule}/${Date.now()}.jpg`;

  // 1. Upload to Supabase Storage bucket 'student-photos'
  const { error: uploadError } = await supabase.storage
    .from('student-photos')
    .upload(nextPath, imageBlob, {
      contentType: 'image/jpeg',
      upsert: true,
    });

  if (uploadError) {
    console.error('Erreur téléversement Storage:', uploadError);
    // If bucket issue, update photo_url with base64 dataUrl directly as fallback!
    try {
      await supabase
        .from('etudiants')
        .update({ photo_url: base64DataUrl })
        .eq('id', student.id);

      saveLocalStudentPhoto(student.matricule, base64DataUrl);
      saveLocalStudentPhoto(student.id, base64DataUrl);

      return {
        success: true,
        photoPath: 'local-fallback',
        signedUrl: base64DataUrl,
      };
    } catch (dbErr) {
      throw new Error(`Échec de la sauvegarde: ${uploadError.message}`);
    }
  }

  // 2. Get signed URL
  let signedUrl = base64DataUrl;
  try {
    const { data: signData } = await supabase.storage
      .from('student-photos')
      .createSignedUrl(nextPath, 86400);
    if (signData?.signedUrl) {
      signedUrl = signData.signedUrl;
    }
  } catch {}

  // 3. Update public.etudiants table
  const { error: updateError } = await supabase
    .from('etudiants')
    .update({ photo_url: nextPath })
    .eq('id', student.id);

  if (updateError) {
    console.error('Erreur mise à jour table etudiants:', updateError);
    throw new Error(`La photo est stockée mais la base n'a pas pu être mise à jour: ${updateError.message}`);
  }

  // 4. Save to local cache
  saveLocalStudentPhoto(student.matricule, base64DataUrl);
  saveLocalStudentPhoto(student.id, base64DataUrl);

  return {
    success: true,
    photoPath: nextPath,
    signedUrl,
  };
}
