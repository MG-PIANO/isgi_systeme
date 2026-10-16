import { useCallback, useEffect, useState } from 'react';
import { Check, Clipboard, LoaderCircle, RefreshCw, ShieldCheck, UserRound, UsersRound, X } from 'lucide-react';
import { supabase } from '../../db/supabaseClient';

interface LinkedStudent {
  matricule: string;
  student: { nom: string; prenom: string; filiere?: string; niveau?: string; statut?: string; telephone?: string; nom_tuteur?: string; telephone_tuteur?: string } | null;
}
interface AccountRequest {
  user_id: string; username: string; account_type: 'etudiant' | 'tuteur'; full_name: string; status: 'pending' | 'approved'; requested_at: string; students: LinkedStudent[];
}
interface StaffResult { requests?: AccountRequest[]; code?: string; instruction?: string; error?: string }

async function staffRequest<T extends StaffResult>(body: Record<string, unknown>): Promise<T> {
  const { data, error } = await supabase.functions.invoke<T>('portal-staff', { body });
  if (error) {
    if (error.context instanceof Response) {
      try {
        const response = await error.context.clone().json() as { error?: unknown };
        if (typeof response.error === 'string') throw new Error(response.error);
      } catch (reason) {
        if (reason instanceof Error && reason.message !== 'Unexpected end of JSON input') throw reason;
      }
    }
    throw new Error(error.message);
  }
  if (!data) throw new Error('Le serveur n’a retourné aucune réponse.');
  if (data.error) throw new Error(data.error);
  return data;
}

export function AccountRequestsPage() {
  const [requests, setRequests] = useState<AccountRequest[]>([]);
  const [loading, setLoading] = useState(true);
  const [busyId, setBusyId] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const loadRequests = useCallback(async () => {
    setLoading(true); setError('');
    try {
      const { data: accounts, error: accErr } = await supabase
        .from('portal_accounts')
        .select('user_id, username, account_type, full_name, status, requested_at')
        .eq('status', 'pending')
        .order('requested_at', { ascending: false });

      if (accErr) throw new Error(accErr.message);

      const userIds = (accounts || []).map((a) => a.user_id);
      const { data: links } = userIds.length
        ? await supabase.from('portal_account_students').select('*').in('user_id', userIds)
        : { data: [] };

      const matricules = [...new Set((links || []).map((l: any) => l.matricule))];
      const { data: students } = matricules.length
        ? await supabase.from('etudiants').select('id, matricule, nom, prenom, filiere, niveau, statut, telephone, nom_tuteur, telephone_tuteur').in('matricule', matricules)
        : { data: [] };

      const formatted: AccountRequest[] = (accounts || []).map((account: any) => ({
        ...account,
        students: (links || [])
          .filter((link: any) => link.user_id === account.user_id)
          .map((link: any) => ({
            matricule: link.matricule,
            student: (students || []).find((s: any) => s.matricule === link.matricule) || null,
          })),
      }));

      setRequests(formatted);
    } catch (reason) {
      try {
        const data = await staffRequest<StaffResult>({ action: 'list' });
        setRequests(data.requests || []);
      } catch {
        setError(reason instanceof Error ? reason.message : 'Chargement des demandes impossible.');
      }
    } finally { setLoading(false); }
  }, []);
  useEffect(() => { void loadRequests(); }, [loadRequests]);

  async function decide(request: AccountRequest, action: 'approve' | 'reject') {
    const question = action === 'reject'
      ? `Refuser la demande de ${request.full_name} ?`
      : `Valider directement l’accès au portail pour ${request.full_name} ? L'accès sera débloqué immédiatement.`;
    if (!window.confirm(question)) return;
    setBusyId(request.user_id); setError(''); setMessage('');
    try {
      const newStatus = action === 'approve' ? 'active' : 'rejected';
      const { error: updErr } = await supabase
        .from('portal_accounts')
        .update({
          status: newStatus,
          reviewed_at: new Date().toISOString(),
        })
        .eq('user_id', request.user_id);

      if (updErr) {
        // Fallback staffRequest
        await staffRequest<StaffResult>({ action, userId: request.user_id });
      }

      setMessage(action === 'approve'
        ? `Le compte de ${request.full_name} a été validé avec succès ! L'étudiant a désormais accès à son espace.`
        : `La demande de ${request.full_name} a été refusée.`);
      await loadRequests();
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Traitement de la demande impossible.'); }
    finally { setBusyId(''); }
  }

  return <div className="space-y-6">
    <div className="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-widest text-primary">Accès au portail étudiant & famille</p>
        <h1 className="mt-2 text-2xl font-bold text-on-surface">Validation des demandes de comptes</h1>
        <p className="mt-2 max-w-2xl text-sm text-on-surface-variant">Vérifiez les matricules et l’identité de l’étudiant. La validation débloque immédiatement son accès au portail.</p>
      </div>
      <button type="button" onClick={() => void loadRequests()} className="inline-flex items-center gap-2 rounded-xl border border-outline-variant px-4 py-2.5 text-sm font-semibold text-on-surface hover:bg-surface-container-high">
        <RefreshCw size={16} className={loading ? 'animate-spin' : ''} /> Actualiser
      </button>
    </div>
    {error && <div role="alert" className="rounded-xl border border-error/30 bg-error-container p-4 text-sm text-on-error-container">{error}</div>}
    {message && <div role="status" className="rounded-xl border border-primary/20 bg-primary-container/30 p-4 text-sm text-on-surface">{message}</div>}
    {loading ? <div className="flex items-center justify-center gap-3 rounded-2xl border border-outline-variant p-12 text-on-surface-variant"><LoaderCircle className="animate-spin" />Chargement des demandes…</div>
      : requests.length === 0 ? <div className="rounded-2xl border border-outline-variant bg-surface-container-low p-10 text-center text-sm text-on-surface-variant">Aucune demande en attente de validation.</div>
        : <div className="grid gap-4 xl:grid-cols-2">{requests.map((request) => {
          const missingStudent = request.students.some((item) => !item.student);
          return <article key={request.user_id} className="rounded-2xl border border-outline-variant bg-surface-container-low p-5">
            <div className="flex items-start justify-between gap-3">
              <div className="flex items-center gap-3">
                <div className="rounded-xl bg-primary/10 p-3 text-primary">{request.account_type === 'tuteur' ? <UsersRound /> : <UserRound />}</div>
                <div>
                  <h2 className="font-bold text-on-surface">{request.full_name}</h2>
                  <p className="mt-1 text-xs text-on-surface-variant">@{request.username} · {request.account_type === 'tuteur' ? 'Tuteur / parent' : 'Étudiant'}</p>
                </div>
              </div>
              <span className="text-right text-[11px] text-amber-600 font-semibold bg-amber-50 px-2.5 py-1 rounded-lg border border-amber-200">
                En attente<br /><span className="text-on-surface-variant font-normal">{new Date(request.requested_at).toLocaleDateString('fr-FR')}</span>
              </span>
            </div>
            <div className="mt-4 space-y-2">
              {request.students.map((link) => (
                <div key={link.matricule} className="rounded-xl border border-outline-variant/70 bg-surface-container-lowest p-3 text-sm">
                  <p className="font-mono text-xs font-bold text-primary">{link.matricule}</p>
                  {link.student ? (
                    <>
                      <p className="mt-1 text-on-surface">{link.student.prenom} {link.student.nom}<span className="text-on-surface-variant"> · {link.student.filiere || 'Filière inconnue'} {link.student.niveau || ''} · {link.student.statut || 'Statut non renseigné'}</span></p>
                      <p className="mt-1 text-xs text-on-surface-variant">Étudiant : {link.student.telephone || 'téléphone non renseigné'} · Parent/tuteur : {link.student.nom_tuteur || 'à confirmer'} {link.student.telephone_tuteur || ''}</p>
                    </>
                  ) : <p className="mt-1 text-error">Matricule absent de la base étudiante</p>}
                </div>
              ))}
            </div>
            <div className="mt-5 flex gap-3">
              <button
                type="button"
                disabled={Boolean(busyId) || missingStudent}
                onClick={() => void decide(request, 'approve')}
                className="inline-flex flex-1 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                <ShieldCheck size={16} />{busyId === request.user_id ? 'Validation…' : 'Valider directement'}
              </button>
              <button
                type="button"
                disabled={Boolean(busyId)}
                onClick={() => void decide(request, 'reject')}
                className="inline-flex items-center justify-center gap-2 rounded-xl border border-error/30 px-4 py-2.5 text-sm font-semibold text-error hover:bg-error-container disabled:opacity-50"
              >
                <X size={16} />Refuser
              </button>
            </div>
          </article>
        })}</div>}
  </div>;
}
