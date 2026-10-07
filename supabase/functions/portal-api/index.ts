import {
  assertMethod,
  clientsForRequest,
  corsHeaders,
  errorMessage,
  hashCode,
  jsonResponse,
  normalizeUsername,
  requireUser,
} from '../_shared/portal.ts';

const PORTAL_EMAIL_DOMAIN = 'accounts.isgi.cg';

Deno.serve(async (request) => {
  if (request.method === 'OPTIONS') return new Response('ok', { headers: corsHeaders });

  try {
    assertMethod(request);
    const body = await request.json();
    const { userClient, adminClient } = clientsForRequest(request);

    if (body.action === 'register') {
      const remoteAddress = request.headers.get('x-forwarded-for')?.split(',')[0].trim()
        || request.headers.get('cf-connecting-ip')?.trim();
      if (!remoteAddress) throw new Error('Origine de la requête indisponible. Réessayez plus tard.');
      const ipDigest = await hashCode(`${remoteAddress}:signup`);
      const { data: signupAllowed, error: rateLimitError } = await adminClient.rpc('portal_consume_rate_limit', {
        p_key_hash: ipDigest,
        p_max_attempts: 5,
        p_window_seconds: 3600,
      });
      if (rateLimitError) throw new Error(`Contrôle anti-abus indisponible: ${rateLimitError.message}`);
      if (!signupAllowed) throw new Error('Trop de demandes depuis cet accès. Réessayez dans une heure.');

      const username = normalizeUsername(body.username);
      const password = typeof body.password === 'string' ? body.password : '';
      const accountType = body.accountType;
      const fullName = typeof body.fullName === 'string' ? body.fullName.trim() : '';
      const matricules = Array.isArray(body.matricules)
        ? [...new Set(body.matricules.map((item: unknown) => String(item).trim().toUpperCase()).filter(Boolean))]
        : [];

      if (password.length < 10 || password.length > 72) {
        throw new Error('Le mot de passe doit contenir entre 10 et 72 caractères.');
      }
      if (!fullName || fullName.length > 160) throw new Error('Veuillez saisir votre nom complet.');
      if (accountType !== 'etudiant' && accountType !== 'tuteur') throw new Error('Type de compte invalide.');
      if ((accountType === 'etudiant' && matricules.length !== 1)
        || (accountType === 'tuteur' && (matricules.length < 1 || matricules.length > 10))) {
        throw new Error(accountType === 'etudiant'
          ? 'Un seul matricule étudiant est requis.'
          : 'Un tuteur doit saisir entre 1 et 10 matricules.');
      }

      const { data: students, error: studentError } = await adminClient
        .from('etudiants')
        .select('id, matricule, nom, prenom')
        .in('matricule', matricules);
      if (studentError) throw new Error(`Vérification des matricules impossible: ${studentError.message}`);
      if (!students || students.length !== matricules.length) {
        throw new Error('Un ou plusieurs matricules ne correspondent pas à un étudiant inscrit.');
      }
      if (accountType === 'etudiant' && students[0].matricule.toUpperCase() !== matricules[0]) {
        throw new Error('Le matricule saisi ne correspond pas à un étudiant.');
      }

      const syntheticEmail = `${username}@${PORTAL_EMAIL_DOMAIN}`;
      const { data: authData, error: authError } = await adminClient.auth.admin.createUser({
        email: syntheticEmail,
        password,
        email_confirm: true,
        user_metadata: { username, account_type: accountType },
      });
      if (authError || !authData.user) {
        throw new Error(authError?.message.includes('already') || authError?.message.includes('exists')
          ? 'Ce nom d’utilisateur est déjà utilisé.'
          : `Création du compte impossible: ${authError?.message || 'réponse Auth vide'}`);
      }

      const { error: accountError } = await adminClient.from('portal_accounts').insert({
        user_id: authData.user.id,
        username,
        account_type: accountType,
        full_name: fullName,
        status: 'pending',
      });
      if (accountError) {
        await adminClient.auth.admin.deleteUser(authData.user.id);
        throw new Error(accountError.code === '23505'
          ? 'Ce nom d’utilisateur a déjà été demandé.'
          : `Enregistrement de la demande impossible: ${accountError.message}`);
      }

      const links = students.map((student: { id: string; matricule: string }) => ({
        user_id: authData.user.id,
        student_id: String(student.id),
        matricule: student.matricule,
      }));
      const { error: linkError } = await adminClient.from('portal_account_students').insert(links);
      if (linkError) {
        await adminClient.auth.admin.deleteUser(authData.user.id);
        throw new Error(`Association des matricules impossible: ${linkError.message}`);
      }

      return jsonResponse({
        status: 'pending',
        message: 'Demande envoyée. L’administration vérifiera votre identité et vous communiquera un code d’activation.',
      }, 201);
    }

    const user = await requireUser(request, userClient);
    if (body.action === 'redeem-code') {
      const activationKey = await hashCode(`${user.id}:activation`);
      const { data: activationAllowed, error: activationRateLimitError } = await adminClient.rpc('portal_consume_rate_limit', {
        p_key_hash: activationKey,
        p_max_attempts: 10,
        p_window_seconds: 3600,
      });
      if (activationRateLimitError) throw new Error(`Contrôle anti-abus indisponible: ${activationRateLimitError.message}`);
      if (!activationAllowed) throw new Error('Trop de tentatives. Réessayez dans une heure.');

      const code = typeof body.code === 'string' ? body.code.trim().toUpperCase() : '';
      if (!/^[2-9A-HJ-NP-Z]{12}$/.test(code)) throw new Error('Code d’activation invalide.');
      const { data: account, error: accountError } = await adminClient
        .from('portal_accounts')
        .select('status, activation_code_hash')
        .eq('user_id', user.id)
        .maybeSingle();
      if (accountError) throw new Error(`Lecture du compte impossible: ${accountError.message}`);
      if (!account || account.status !== 'approved' || !account.activation_code_hash) {
        throw new Error('Aucun code d’activation valide n’est associé à ce compte.');
      }
      if (await hashCode(code) !== account.activation_code_hash) throw new Error('Code d’activation incorrect.');

      const { error: updateError } = await adminClient
        .from('portal_accounts')
        .update({ status: 'active', activated_at: new Date().toISOString(), activation_code_hash: null })
        .eq('user_id', user.id)
        .eq('status', 'approved');
      if (updateError) throw new Error(`Activation du compte impossible: ${updateError.message}`);
      return jsonResponse({ status: 'active', message: 'Compte activé.' });
    }

    if (body.action === 'account-status') {
      const { data, error } = await adminClient
        .from('portal_accounts')
        .select('username, account_type, full_name, status')
        .eq('user_id', user.id)
        .maybeSingle();
      if (error) throw new Error(`Lecture du compte impossible: ${error.message}`);
      if (!data) throw new Error('Ce compte n’est pas enregistré dans le portail.');
      return jsonResponse(data);
    }

    if (body.action === 'dashboard') {
      const { data: account, error: accountError } = await adminClient
        .from('portal_accounts')
        .select('username, account_type, full_name, status')
        .eq('user_id', user.id)
        .eq('status', 'active')
        .maybeSingle();
      if (accountError) throw new Error(`Lecture du compte impossible: ${accountError.message}`);
      if (!account) throw new Error('Le compte doit être activé avant d’accéder aux informations scolaires.');

      const { data: links, error: linkError } = await adminClient
        .from('portal_account_students')
        .select('student_id, matricule')
        .eq('user_id', user.id);
      if (linkError) throw new Error(`Lecture des étudiants associés impossible: ${linkError.message}`);
      if (!links?.length) throw new Error('Aucun étudiant validé n’est associé à ce compte.');

      const references = [...new Set(links.flatMap((link: { student_id: string; matricule: string }) => [
        link.student_id, link.matricule,
      ]))];
      const { data: students, error: studentsError } = await adminClient
        .from('etudiants')
        .select('*')
        .in('matricule', links.map((link: { matricule: string }) => link.matricule));
      if (studentsError) throw new Error(`Lecture des dossiers étudiants impossible: ${studentsError.message}`);
      if (!students?.length) throw new Error('Les dossiers étudiants associés sont introuvables.');

      const [{ data: memberships, error: membershipsError }, { data: notes, error: notesError }, { data: presences, error: presencesError }, { data: payments, error: paymentsError }] =
        await Promise.all([
          adminClient.from('classe_etudiants').select('*').in('etudiant_id', references),
          adminClient.from('notes').select('*').in('etudiant_id', references).eq('publie', true),
          adminClient.from('presences_etudiants').select('*').in('etudiant_id', references),
          adminClient.from('paiements').select('*').in('etudiant_id', references),
        ]);
      if (membershipsError) throw new Error(`Lecture des classes impossible: ${membershipsError.message}`);
      if (notesError) throw new Error(`Lecture des résultats publiés impossible: ${notesError.message}`);
      if (presencesError) throw new Error(`Lecture des présences impossible: ${presencesError.message}`);
      if (paymentsError) throw new Error(`Lecture des paiements impossible: ${paymentsError.message}`);

      const classIds = [...new Set([
        ...(memberships || []).map((item: { classe_id: string }) => String(item.classe_id)),
        ...students.map((student: { classe_id?: string }) => student.classe_id).filter(Boolean),
      ])];
      const [{ data: classes, error: classesError }, { data: timetable, error: timetableError }, { data: videos, error: videosError }] =
        await Promise.all([
          classIds.length
            ? adminClient.from('classes').select('*').in('id', classIds)
            : Promise.resolve({ data: [], error: null }),
          classIds.length
            ? adminClient.from('emplois_du_temps').select('*').in('classe_id', classIds).eq('publie', true)
            : Promise.resolve({ data: [], error: null }),
          adminClient.from('videos').select('*').eq('statut', 'publie'),
        ]);
      if (classesError) throw new Error(`Lecture des classes impossible: ${classesError.message}`);
      if (timetableError) throw new Error(`Lecture de l’emploi du temps impossible: ${timetableError.message}`);
      if (videosError) throw new Error(`Lecture des vidéos publiées impossible: ${videosError.message}`);

      const classNames = new Set([
        ...(classes || []).map((item: { nom?: string }) => item.nom).filter(Boolean),
        ...students.map((student: { classe_nom?: string; classe?: string }) => student.classe_nom || student.classe).filter(Boolean),
      ]);
      const visibleVideos = (videos || []).filter((video: { visibilite: string; classe_id?: string; classe_nom?: string }) =>
        video.visibilite === 'publique'
        || video.visibilite === 'public'
        || (video.visibilite === 'classe'
          && (classIds.includes(String(video.classe_id || '')) || classNames.has(video.classe_nom || ''))));
      const signedVideos = await Promise.all(visibleVideos.map(async (video: Record<string, unknown>) => ({
        ...video,
        url_video: await signStorageUrl(adminClient, String(video.url_video || ''), 'videos-isgi'),
        url_miniature: await signStorageUrl(adminClient, String(video.url_miniature || ''), 'videos-isgi'),
      })));
      const safeStudents = await Promise.all(students.map(async (student: Record<string, unknown>) => ({
        id: student.id,
        matricule: student.matricule,
        nom: student.nom,
        prenom: student.prenom,
        filiere: student.filiere,
        niveau: student.niveau,
        annee_academique: student.annee_academique,
        statut: student.statut,
        qr_code_data: student.qr_code_data,
        class_ids: [...new Set([
          ...((memberships || [])
            .filter((item: { etudiant_id: string }) => [String(student.id), String(student.matricule)].includes(String(item.etudiant_id)))
            .map((item: { classe_id: string }) => String(item.classe_id))),
          student.classe_id ? String(student.classe_id) : '',
        ].filter(Boolean))],
        photo_url: await signStorageUrl(adminClient, String(student.photo_url || ''), 'student-photos'),
        classe: student.classe_nom || student.classe
          || (classes || []).find((item: { id: string }) => item.id === student.classe_id)?.nom
          || null,
      })));

      const subjectIds = [...new Set((timetable || []).map((item: { matiere_id?: string }) => item.matiere_id).filter(Boolean))];
      const { data: subjects, error: subjectsError } = subjectIds.length
        ? await adminClient.from('matieres').select('id, nom, code').in('id', subjectIds)
        : { data: [], error: null };
      if (subjectsError) throw new Error(`Lecture des matières impossible: ${subjectsError.message}`);

      return jsonResponse({
        account,
        students: safeStudents,
        grades: notes || [],
        attendance: presences || [],
        payments: payments || [],
        timetable: timetable || [],
        subjects: subjects || [],
        classes: classes || [],
        videos: signedVideos,
      });
    }

    throw new Error('Action du portail inconnue.');
  } catch (error) {
    const message = errorMessage(error);
    const status = /Connexion requise|Session invalide|Accès réservé/.test(message) ? 401 : 400;
    return jsonResponse({ error: message }, status);
  }
});

async function signStorageUrl(
  adminClient: ReturnType<typeof clientsForRequest>['adminClient'],
  value: string,
  bucket: string,
): Promise<string | null> {
  if (!value) return null;
  const match = value.match(/\/object\/(?:public|sign)\/([^/]+)\/(.+?)(?:\?|$)/);
  const storageBucket = match?.[1] || bucket;
  const path = match?.[2] || (value.startsWith('http') ? null : value);
  if (!path) return value;
  const { data, error } = await adminClient.storage.from(storageBucket).createSignedUrl(decodeURIComponent(path), 900);
  if (error) {
    if (error.message.toLowerCase().includes('not found') || error.message.toLowerCase().includes('does not exist')) return null;
    throw new Error(`Création du lien privé impossible: ${error.message}`);
  }
  return data.signedUrl;
}
