<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FraisScolariteSeeder extends Seeder
{
    public function run()
    {
        $frais = [
            // Inscriptions et réinscriptions
            ['type_frais' => 'inscription', 'montant' => 25000, 'cycle' => 'Local'],
            ['type_frais' => 'reinscription', 'montant' => 20000, 'cycle' => 'Local'],
            
            // Frais mensuels
            ['type_frais' => 'mensualite', 'niveau_etude' => 'Licence 1', 'montant' => 25000, 'cycle' => 'Local'],
            ['type_frais' => 'mensualite', 'niveau_etude' => 'Licence 2', 'montant' => 30000, 'cycle' => 'Local'],
            ['type_frais' => 'mensualite', 'niveau_etude' => 'Licence 3', 'montant' => 35000, 'cycle' => 'Local'],
            
            // Cycle International (Annuel)
            ['type_frais' => 'annuel', 'cycle' => 'International', 'destination' => 'France', 'montant' => 2300000],
            ['type_frais' => 'annuel', 'cycle' => 'International', 'destination' => 'Londres', 'montant' => 1000000], // Pris le max de 800000 - 1000000
            
            // Cycle Local - Gestion & Technologie
            ['type_frais' => 'annuel', 'cycle' => 'Local', 'cursus_domaine' => 'Gestion & Technologie', 'niveau_etude' => 'Licence 1', 'montant' => 250000],
            ['type_frais' => 'annuel', 'cycle' => 'Local', 'cursus_domaine' => 'Gestion & Technologie', 'niveau_etude' => 'Licence 2', 'montant' => 300000],
            ['type_frais' => 'annuel', 'cycle' => 'Local', 'cursus_domaine' => 'Gestion & Technologie', 'niveau_etude' => 'Licence 3', 'montant' => 350000],
            
            // Cycle Local - Industrie
            ['type_frais' => 'annuel', 'cycle' => 'Local', 'cursus_domaine' => 'Industrie', 'niveau_etude' => 'Licence 1', 'montant' => 350000],
            ['type_frais' => 'annuel', 'cycle' => 'Local', 'cursus_domaine' => 'Industrie', 'niveau_etude' => 'Licence 2', 'montant' => 400000],
            ['type_frais' => 'annuel', 'cycle' => 'Local', 'cursus_domaine' => 'Industrie', 'niveau_etude' => 'Licence 3', 'montant' => 450000],
        ];

        foreach ($frais as $f) {
            $f['uuid'] = Str::uuid()->toString();
            $f['created_at'] = now();
            $f['updated_at'] = now();
            $f['last_modified_at'] = now();
            DB::table('frais_scolarites')->insert($f);
        }
    }
}
