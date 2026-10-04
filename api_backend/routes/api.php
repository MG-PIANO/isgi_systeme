<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\EtudiantController;
use App\Http\Controllers\API\PaiementController;
use App\Http\Controllers\API\SyncController;
use App\Models\FraisScolarite;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login']);

// Grille tarifaire publique ou récupérable par l'app offline
Route::get('/frais-scolarite', function () {
    return response()->json(FraisScolarite::all());
});

// Synchronisation Offline (Temporarily Public for dev)
Route::get('/sync/pull', [SyncController::class, 'pull']);
Route::post('/sync/push', [SyncController::class, 'push']);

// Routes protégées
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // CRUD Etudiants & Paiements
    Route::apiResource('etudiants', EtudiantController::class);
    Route::apiResource('paiements', PaiementController::class);
});
