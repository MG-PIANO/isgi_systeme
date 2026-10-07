import {
  activationCode,
  assertMethod,
  clientsForRequest,
  corsHeaders,
  errorMessage,
  hashCode,
  jsonResponse,
  requireStaff,
  requireUser,
} from '../_shared/portal.ts';

Deno.serve(async (request) => {
  if (request.method === 'OPTIONS') return new Response('ok', { headers: corsHeaders });

  try {
    assertMethod(request);
    const body = await request.json();
    const { userClient, adminClient } = clientsForRequest(request);
    const user = await requireUser(request, userClient);
    requireStaff(user.id);

    if (body.action === 'list') {
      const { data: accounts, error } = await adminClient
        .from('portal_accounts')
        .select('user_id, username, account_type, full_name, status, requested_at')
        .in('status', ['pending', 'approved'])
        .order('requested_at', { ascending: true });
      if (error) throw new Error(`Chargement des demandes impossible: ${error.message}`);

      const userIds = (accounts || []).map((account: { user_id: string }) => account.user_id);
      const { data: links, error: linksError } = userIds.length
        ? await adminClient.from('portal_account_students').select('*').in('user_id', userIds)
        : { data: [], error: null };
      if (linksError) throw new Error(`Chargement des matricules impossible: ${linksError.message}`);

      const matricules = [...new Set((links || []).map((link: { matricule: string }) => link.matricule))];
      const { data: students, error: studentsError } = matricules.length
        ? await adminClient.from('etudiants').select('id, matricule, nom, prenom, filiere, niveau, statut, telephone, nom_tuteur, telephone_tuteur').in('matricule', matricules)
        : { data: [], error: null };
      if (studentsError) throw new Error(`Vérification des dossiers étudiants impossible: ${studentsError.message}`);

      return jsonResponse({
        requests: (accounts || []).map((account: Record<string, unknown>) => ({
          ...account,
          students: (links || [])
            .filter((link: { user_id: string }) => link.user_id === account.user_id)
            .map((link: { matricule: string }) => ({
              matricule: link.matricule,
              student: (students || []).find((student: { matricule: string }) => student.matricule === link.matricule) || null,
            })),
        })),
      });
    }

    if (body.action === 'approve' || body.action === 'reject' || body.action === 'reissue') {
      const userId = typeof body.userId === 'string' ? body.userId : '';
      if (!/^[0-9a-f-]{36}$/i.test(userId)) throw new Error('Demande invalide.');
      const { data: account, error: accountError } = await adminClient
        .from('portal_accounts')
        .select('user_id, status, activation_code_hash')
        .eq('user_id', userId)
        .maybeSingle();
      if (accountError) throw new Error(`Lecture de la demande impossible: ${accountError.message}`);
      const expectedStatus = body.action === 'reissue' ? 'approved' : 'pending';
      if (!account || account.status !== expectedStatus) throw new Error('Cette demande a déjà été traitée ou le code a déjà été utilisé.');

      if (body.action === 'reject') {
        const { error } = await adminClient
          .from('portal_accounts')
          .update({ status: 'rejected', reviewed_at: new Date().toISOString(), reviewed_by: user.id })
          .eq('user_id', userId)
          .eq('status', 'pending');
        if (error) throw new Error(`Rejet de la demande impossible: ${error.message}`);
        return jsonResponse({ status: 'rejected' });
      }

      if (body.action === 'reissue' && !account.activation_code_hash) {
        throw new Error('Ce code a déjà été utilisé. Le compte est déjà activé.');
      }

      const { data: requestedLinks, error: requestedLinksError } = await adminClient
        .from('portal_account_students')
        .select('matricule')
        .eq('user_id', userId);
      if (requestedLinksError) throw new Error(`Vérification des matricules impossible: ${requestedLinksError.message}`);
      if (!requestedLinks?.length) throw new Error('Aucun matricule n’est rattaché à cette demande.');
      const { data: verifiedStudents, error: verifiedStudentsError } = await adminClient
        .from('etudiants')
        .select('matricule')
        .in('matricule', requestedLinks.map((link: { matricule: string }) => link.matricule));
      if (verifiedStudentsError) throw new Error(`Vérification des dossiers impossible: ${verifiedStudentsError.message}`);
      if (verifiedStudents?.length !== requestedLinks.length) {
        throw new Error('Un matricule n’existe plus dans la base. Vérifiez le dossier avant validation.');
      }

      const code = activationCode();
      const { data: updated, error } = await adminClient
        .from('portal_accounts')
        .update({
          status: 'approved',
          activation_code_hash: await hashCode(code),
          reviewed_at: new Date().toISOString(),
          reviewed_by: user.id,
        })
        .eq('user_id', userId)
        .eq('status', expectedStatus)
        .select('user_id')
        .maybeSingle();
      if (error) throw new Error(`Validation de la demande impossible: ${error.message}`);
      if (!updated) throw new Error('Cette demande a déjà été traitée par un autre agent.');
      return jsonResponse({
        status: 'approved',
        code,
        instruction: 'Remettez ce code une seule fois à la personne après vérification physique de son identité.',
      });
    }

    throw new Error('Action d’administration inconnue.');
  } catch (error) {
    const message = errorMessage(error);
    const status = /Connexion requise|Session invalide|Accès réservé/.test(message) ? 401 : 400;
    return jsonResponse({ error: message }, status);
  }
});
