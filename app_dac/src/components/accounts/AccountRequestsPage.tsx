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
  const [issuedCode, setIssuedCode] = useState('');

  const loadRequests = useCallback(async () => {
    setLoading(true); setError('');
    try {
      const data = await staffRequest<StaffResult>({ action: 'list' });
      setRequests(data.requests || []);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Chargement des demandes impossible.');
    } finally { setLoading(false); }
  }, []);
  useEffect(() => { void loadRequests(); }, [loadRequests]);

  async function decide(request: AccountRequest, action: 'approve' | 'reject' | 'reissue') {
    const question = action === 'reject' ? `Refuser la demande de ${request.full_name} ?`
      : action === 'reissue' ? `Générer un nouveau code pour ${request.full_name} ? L'ancien code cessera de fonctionner.`
        : `Confirmer après vérification d'identité de ${request.full_name} ? Le code sera affiché une seule fois.`;
    if (!window.confirm(question)) return;
    setBusyId(request.user_id); setError(''); setMessage(''); setIssuedCode('');
    try {
      const data = await staffRequest<StaffResult>({ action, userId: request.user_id });
      if (action !== 'reject' && data?.code) { setIssuedCode(data.code); setMessage(data.instruction || 'Remettez le code en personne après contrôle.'); }
      else setMessage('La demande a été refusée.');
      await loadRequests();
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Traitement de la demande impossible.'); }
    finally { setBusyId(''); }
  }

  async function copyCode() {
    try { await navigator.clipboard.writeText(issuedCode); setMessage('Code copié. Remettez-le uniquement à la personne vérifiée.'); }
    catch { setError('Copie impossible. Sélectionnez et copiez le code manuellement.'); }
  }

  return <div className="space-y-6">
    <div className="flex flex-wrap items-start justify-between gap-4"><div><p className="text-xs font-semibold uppercase tracking-widest text-primary">Accès au portail famille</p><h1 className="mt-2 text-2xl font-bold text-on-surface">Demandes de comptes</h1><p className="mt-2 max-w-2xl text-sm text-on-surface-variant">Comparez les matricules avec les dossiers et contrôlez l’identité avant de valider. Le code doit être remis en personne.</p></div><button type="button" onClick={() => void loadRequests()} className="inline-flex items-center gap-2 rounded-xl border border-outline-variant px-4 py-2.5 text-sm font-semibold text-on-surface hover:bg-surface-container-high"><RefreshCw size={16} className={loading ? 'animate-spin' : ''} /> Actualiser</button></div>
    {error && <div role="alert" className="rounded-xl border border-error/30 bg-error-container p-4 text-sm text-on-error-container">{error}</div>}
    {message && <div role="status" className="rounded-xl border border-primary/20 bg-primary-container/30 p-4 text-sm text-on-surface">{message}</div>}
    {issuedCode && <div className="rounded-2xl border border-emerald-500/30 bg-emerald-950/20 p-5"><div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="font-bold text-on-surface">Code d’activation — affiché une seule fois</h2><p className="mt-1 text-xs text-on-surface-variant">Remettez-le en personne au demandeur après vérification.</p></div><button type="button" onClick={() => void copyCode()} className="inline-flex items-center gap-2 rounded-lg bg-emerald-500 px-3 py-2 text-sm font-bold text-slate-950"><Clipboard size={15} /> Copier</button></div><p className="mt-4 select-all rounded-lg bg-slate-950 px-4 py-3 font-mono text-2xl font-bold tracking-[0.22em] text-emerald-300">{issuedCode}</p></div>}
    {loading ? <div className="flex items-center justify-center gap-3 rounded-2xl border border-outline-variant p-12 text-on-surface-variant"><LoaderCircle className="animate-spin" />Chargement des demandes…</div>
      : requests.length === 0 ? <div className="rounded-2xl border border-outline-variant bg-surface-container-low p-10 text-center text-sm text-on-surface-variant">Aucune demande en attente.</div>
        : <div className="grid gap-4 xl:grid-cols-2">{requests.map((request) => {
          const missingStudent = request.students.some((item) => !item.student);
          return <article key={request.user_id} className="rounded-2xl border border-outline-variant bg-surface-container-low p-5">
            <div className="flex items-start justify-between gap-3"><div className="flex items-center gap-3"><div className="rounded-xl bg-primary/10 p-3 text-primary">{request.account_type === 'tuteur' ? <UsersRound /> : <UserRound />}</div><div><h2 className="font-bold text-on-surface">{request.full_name}</h2><p className="mt-1 text-xs text-on-surface-variant">@{request.username} · {request.account_type === 'tuteur' ? 'Tuteur / parent' : 'Étudiant'}</p></div></div><span className="text-right text-[11px] text-on-surface-variant">{request.status === 'approved' ? 'En attente d’activation' : 'À vérifier'}<br />{new Date(request.requested_at).toLocaleDateString('fr-FR')}</span></div>
            <div className="mt-4 space-y-2">{request.students.map((link) => <div key={link.matricule} className="rounded-xl border border-outline-variant/70 bg-surface-container-lowest p-3 text-sm"><p className="font-mono text-xs font-bold text-primary">{link.matricule}</p>{link.student ? <><p className="mt-1 text-on-surface">{link.student.prenom} {link.student.nom}<span className="text-on-surface-variant"> · {link.student.filiere || 'Filière inconnue'} {link.student.niveau || ''} · {link.student.statut || 'Statut non renseigné'}</span></p><p className="mt-1 text-xs text-on-surface-variant">Étudiant : {link.student.telephone || 'téléphone non renseigné'} · Parent/tuteur : {link.student.nom_tuteur || 'à confirmer'} {link.student.telephone_tuteur || ''}</p></> : <p className="mt-1 text-error">Matricule absent de la base étudiante</p>}</div>)}</div>
            <div className="mt-5 flex gap-3">{request.status === 'pending' ? <><button type="button" disabled={Boolean(busyId) || missingStudent} onClick={() => void decide(request, 'approve')} className="inline-flex flex-1 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-50"><ShieldCheck size={16} />{busyId === request.user_id ? 'Validation…' : 'Vérifié : valider'}</button><button type="button" disabled={Boolean(busyId)} onClick={() => void decide(request, 'reject')} className="inline-flex items-center justify-center gap-2 rounded-xl border border-error/30 px-4 py-2.5 text-sm font-semibold text-error hover:bg-error-container disabled:opacity-50"><X size={16} />Refuser</button></> : <button type="button" disabled={Boolean(busyId)} onClick={() => void decide(request, 'reissue')} className="inline-flex flex-1 items-center justify-center gap-2 rounded-xl border border-primary/30 px-4 py-2.5 text-sm font-semibold text-primary hover:bg-primary-container disabled:opacity-50"><ShieldCheck size={16} />Réémettre un code</button>}</div>
            {!missingStudent && <p className="mt-3 text-[11px] text-on-surface-variant">La validation seule ne prouve pas le lien familial : contrôlez les pièces justificatives ou contactez la famille avant de générer le code.</p>}
          </article>
        })}</div>}
  </div>;
}
