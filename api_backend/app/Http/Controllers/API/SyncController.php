<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Etudiant;
use App\Models\Paiement;
use Illuminate\Support\Facades\DB;

class SyncController extends Controller
{
    /**
     * Pull data that has been modified since a given timestamp.
     */
    public function pull(Request $request)
    {
        $lastSync = $request->query('last_sync', '1970-01-01 00:00:00');

        $etudiants = Etudiant::withTrashed()->where('last_modified_at', '>', $lastSync)->get()->map(function ($e) {
            return [
                'id' => $e->uuid,
                'matricule' => $e->matricule,
                'nom' => $e->nom,
                'prenom' => $e->prenom,
                'sexe' => $e->sexe,
                'date_naissance' => $e->date_naissance ? $e->date_naissance->format('Y-m-d') : null,
                'lieu_naissance' => $e->lieu_naissance,
                'nationalite' => $e->nationalite,
                'telephone' => $e->telephone,
                'email' => $e->email,
                'profession' => $e->profession,
                'type_etudiant' => $e->type_etudiant,
                'serie_bac' => $e->serie_bac,
                'filiere' => $e->filiere,
                'niveau' => $e->niveau_etude,
                'cycle_formation' => $e->cycle,
                'rentree' => $e->rentree,
                'nom_pere' => $e->nom_prenom_pere,
                'nom_mere' => $e->nom_prenom_mere,
                'nom_tuteur' => $e->information_tuteur,
                'telephone_tuteur' => $e->telephone4,
                'profession_tuteur' => $e->profession_tuteur,
                'adresse' => $e->adresse_etudiant,
                'last_modified_at' => $e->last_modified_at,
                'deleted_at' => $e->deleted_at
            ];
        });

        $paiements = Paiement::withTrashed()->where('last_modified_at', '>', $lastSync)->get()->map(function ($p) {
            // Need etudiant matricule
            $inscription = \App\Models\Inscription::withTrashed()->find($p->inscription_id);
            $etudiant = $inscription ? \App\Models\Etudiant::withTrashed()->find($inscription->etudiant_id) : null;
            
            return [
                'id' => $p->uuid,
                'etudiant_id' => $etudiant ? $etudiant->matricule : null,
                'type_paiement' => $p->type_paiement,
                'montant' => $p->montant,
                'mode_paiement' => $p->mode_paiement,
                'reference_transaction' => $p->reference_transaction,
                'statut' => $p->statut,
                'gestionnaire_nom' => $p->gestionnaire_nom,
                'last_modified_at' => $p->last_modified_at,
                'deleted_at' => $p->deleted_at
            ];
        });

        return response()->json([
            'timestamp' => now()->toDateTimeString(),
            'changes' => [
                'etudiants' => $etudiants,
                'paiements' => $paiements,
            ]
        ]);
    }

    /**
     * Push local changes from the offline app to the server.
     */
    public function push(Request $request)
    {
        $changes = $request->input('changes', []);
        
        DB::beginTransaction();
        try {
            if (isset($changes['etudiants'])) {
                foreach ($changes['etudiants'] as $etudiantData) {
                    $etudiant = Etudiant::withTrashed()->where('uuid', $etudiantData['uuid'])->first();
                    
                    $mappedData = [
                        'uuid' => $etudiantData['uuid'],
                        'matricule' => $etudiantData['matricule'] ?? 'N/A',
                        'nom' => $etudiantData['nom'] ?? 'N/A',
                        'prenom' => $etudiantData['prenom'] ?? 'N/A',
                        'sexe' => $etudiantData['sexe'] ?? 'M',
                        'date_naissance' => !empty($etudiantData['date_naissance']) ? date('Y-m-d', strtotime($etudiantData['date_naissance'])) : '2000-01-01',
                        'lieu_naissance' => $etudiantData['lieu_naissance'] ?? 'N/A',
                        'nationalite' => $etudiantData['nationalite'] ?? 'CONGOLAISE',
                        'telephone' => $etudiantData['telephone'] ?? 'N/A',
                        'email' => $etudiantData['email'] ?? null,
                        'profession' => $etudiantData['profession'] ?? null,
                        'type_etudiant' => (stripos($etudiantData['type_etudiant'] ?? '', 'bours') !== false) ? 'Boursier' : 'Normal',
                        'annee_obtention_bac' => $etudiantData['annee_obtention_bac'] ?? date('Y'),
                        'serie_bac' => $etudiantData['serie_bac'] ?? 'A',
                        'cycle' => $etudiantData['cycle_formation'] ?? 'LICENCE',
                        'niveau_etude' => $etudiantData['niveau'] ?? 'LICENCE 1',
                        'cursus_domaine_etude' => $etudiantData['option'] ?? 'N/A',
                        'filiere' => $etudiantData['filiere'] ?? 'N/A',
                        'statut' => 'actif',
                        'date_inscription' => date('Y-m-d'),
                        'rentree' => (stripos($etudiantData['rentree'] ?? '', 'janv') !== false) ? 'Janvier' : 'Octobre',
                        'nom_prenom_pere' => $etudiantData['nom_pere'] ?? null,
                        'nom_prenom_mere' => $etudiantData['nom_mere'] ?? null,
                        'information_tuteur' => $etudiantData['nom_tuteur'] ?? null,
                        'telephone4' => $etudiantData['telephone_tuteur'] ?? null,
                        'profession_tuteur' => $etudiantData['profession_tuteur'] ?? null,
                        'adresse_etudiant' => $etudiantData['adresse'] ?? null,
                        'last_modified_at' => isset($etudiantData['last_modified_at']) ? date('Y-m-d H:i:s', strtotime($etudiantData['last_modified_at'])) : now(),
                    ];

                    if (isset($etudiantData['deleted_at']) && $etudiantData['deleted_at']) {
                        $mappedData['deleted_at'] = date('Y-m-d H:i:s', strtotime($etudiantData['deleted_at']));
                    }

                    if ($etudiant) {
                        if (strtotime($etudiantData['last_modified_at']) >= strtotime($etudiant->last_modified_at)) {
                            $etudiant->update($mappedData);
                        }
                    } else {
                        $etudiant = Etudiant::create($mappedData);
                    }
                    
                    // Create an inscription if it doesn't exist to link paiements
                    \App\Models\Inscription::firstOrCreate(
                        ['etudiant_id' => $etudiant->id, 'annee_academique' => '2026-2027'],
                        [
                            'uuid' => (string) \Illuminate\Support\Str::uuid(),
                            'niveau_etude' => $etudiant->niveau_etude,
                            'type_inscription' => 'Inscription',
                            'montant_total_scolarite' => 500000,
                            'montant_paye' => 0,
                            'reste_a_payer' => 500000,
                        ]
                    );
                }
            }

            if (isset($changes['paiements'])) {
                foreach ($changes['paiements'] as $paiementData) {
                    // Find etudiant by matricule
                    $etudiant = Etudiant::where('matricule', $paiementData['etudiant_id'])->first();
                    if (!$etudiant) continue;
                    
                    $inscription = \App\Models\Inscription::where('etudiant_id', $etudiant->id)->first();
                    if (!$inscription) continue;

                    $mappedPaiement = [
                        'uuid' => $paiementData['uuid'],
                        'inscription_id' => $inscription->id,
                        'type_paiement' => $paiementData['type_paiement'] ?? 'Frais Scolaire',
                        'montant' => $paiementData['montant'] ?? 0,
                        'date_paiement' => date('Y-m-d', strtotime($paiementData['last_modified_at'] ?? now())),
                        'mode_paiement' => $paiementData['mode_paiement'] ?? 'Espece',
                        'reference_transaction' => $paiementData['reference_transaction'] ?? null,
                        'statut' => $paiementData['statut'] ?? 'complet',
                        'gestionnaire_nom' => $paiementData['gestionnaire_nom'] ?? null,
                        'last_modified_at' => isset($paiementData['last_modified_at']) ? date('Y-m-d H:i:s', strtotime($paiementData['last_modified_at'])) : now(),
                    ];
                    
                    if (isset($paiementData['deleted_at']) && $paiementData['deleted_at']) {
                        $mappedPaiement['deleted_at'] = date('Y-m-d H:i:s', strtotime($paiementData['deleted_at']));
                    }

                    $paiement = Paiement::withTrashed()->where('uuid', $paiementData['uuid'])->first();
                    if ($paiement) {
                        if (strtotime($paiementData['last_modified_at']) >= strtotime($paiement->last_modified_at)) {
                            $paiement->update($mappedPaiement);
                        }
                    } else {
                        Paiement::create($mappedPaiement);
                    }
                }
            }

            DB::commit();
            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Sync Push Error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
