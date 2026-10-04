<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FraisScolarite extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'type_frais', 'cycle', 'niveau_etude', 'cursus_domaine', 'destination', 'montant', 'devise'
    ];
}
