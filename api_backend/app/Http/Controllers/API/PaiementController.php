<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Paiement;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaiementController extends Controller
{
    public function index()
    {
        return response()->json(Paiement::with('frais_scolarite')->get());
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'inscription_id' => 'required|exists:inscriptions,id',
            'frais_scolarite_id' => 'nullable|exists:frais_scolarites,id',
            'montant' => 'required|numeric',
            'date_paiement' => 'required|date',
            'mode_paiement' => 'required|string',
            'reference_transaction' => 'nullable|string',
            'statut' => 'nullable|string'
        ]);

        $validatedData['uuid'] = $request->uuid ?? Str::uuid()->toString();
        
        $paiement = Paiement::create($validatedData);

        return response()->json($paiement, 201);
    }

    public function show($id)
    {
        $paiement = Paiement::with('frais_scolarite')->findOrFail($id);
        return response()->json($paiement);
    }

    public function update(Request $request, $id)
    {
        $paiement = Paiement::findOrFail($id);
        $paiement->update($request->all());
        $paiement->last_modified_at = now();
        $paiement->save();

        return response()->json($paiement);
    }

    public function destroy($id)
    {
        $paiement = Paiement::findOrFail($id);
        $paiement->delete();

        return response()->json(null, 204);
    }
}
