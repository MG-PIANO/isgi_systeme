<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFraisScolaritesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('frais_scolarites', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // Pour la synchronisation offline
            
            $table->string('type_frais'); // e.g., 'inscription', 'reinscription', 'mensualite', 'annuel', 'examen_semestre_1', 'tp_semestre_1', etc.
            $table->string('cycle')->nullable(); // e.g., 'Local', 'International'
            $table->string('niveau_etude')->nullable(); // e.g., 'Licence 1', 'Licence 2', 'Licence 3'
            $table->string('cursus_domaine')->nullable(); // e.g., 'Gestion & Technologie', 'Industrie'
            $table->string('destination')->nullable(); // e.g., 'France', 'Londres' (pour le cycle international)
            
            $table->decimal('montant', 10, 2);
            $table->string('devise')->default('FCFA');
            
            $table->timestamp('last_modified_at')->useCurrent();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('frais_scolarites');
    }
}
