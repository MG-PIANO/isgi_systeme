<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EtudiantController extends Controller
{
    public function index()
    {
        return response()->json(Etudiant::all());
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'matricule' => 'required|string|unique:etudiants',
            'nom' => 'required|string',
            'prenom' => 'required|string',
            'sexe' => 'required|in:M,F',
            'date_naissance' => 'required|date',
            'lieu_naissance' => 'required|string',
            'nationalite' => 'required|string',
            'telephone' => 'required|string',
            'email' => 'nullable|email',
            'profession' => 'nullable|string',
            'type_etudiant' => 'required|in:Normal,Boursier',
            'annee_obtention_bac' => 'required|integer',
            'serie_bac' => 'required|string',
            'cycle' => 'required|string',
            'niveau_etude' => 'required|string',
            'cursus_domaine_etude' => 'required|string',
            'filiere' => 'required|string',
            'statut' => 'required|string',
            'date_inscription' => 'required|date',
            'rentree' => 'required|in:Octobre,Janvier',
            'nom_prenom_pere' => 'nullable|string',
            'telephone2' => 'nullable|string',
            'nom_prenom_mere' => 'nullable|string',
            'telephone3' => 'nullable|string',
            'information_tuteur' => 'nullable|string',
            'telephone4' => 'nullable|string',
            'profession_tuteur' => 'nullable|string',
            'adresse_etudiant' => 'nullable|string',
        ]);

        $validatedData['uuid'] = $request->uuid ?? Str::uuid()->toString();

        $etudiant = Etudiant::create($validatedData);

        return response()->json($etudiant, 201);
    }

    public function show($id)
    {
        $etudiant = Etudiant::findOrFail($id);
        return response()->json($etudiant);
    }

    public function update(Request $request, $id)
    {
        $etudiant = Etudiant::findOrFail($id);
        $etudiant->update($request->all());
        $etudiant->last_modified_at = now();
        $etudiant->save();

        return response()->json($etudiant);
    }

    public function destroy($id)
    {
        $etudiant = Etudiant::findOrFail($id);
        $etudiant->delete();

        return response()->json(null, 204);
    }
}
