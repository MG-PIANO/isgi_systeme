<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEtudiantsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('etudiants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // Pour la synchronisation offline
            
            // Informations Personnelles
            $table->string('matricule')->unique();
            $table->string('nom');
            $table->string('prenom');
            $table->enum('sexe', ['M', 'F']);
            $table->date('date_naissance');
            $table->string('lieu_naissance');
            $table->string('nationalite');
            $table->string('telephone');
            $table->string('email')->nullable();
            $table->string('profession')->nullable();
            
            // Parcours Académique
            $table->enum('type_etudiant', ['Normal', 'Boursier'])->default('Normal');
            $table->integer('annee_obtention_bac');
            $table->string('serie_bac');
            $table->string('cycle');
            $table->string('niveau_etude');
            $table->string('cursus_domaine_etude');
            $table->string('filiere');
            
            // Statut et Inscription
            $table->enum('statut', ['actif', 'inactif', 'diplome', 'abandonne'])->default('actif');
            $table->date('date_inscription');
            $table->enum('rentree', ['Octobre', 'Janvier'])->default('Octobre');
            
            // Parents / Tuteur
            $table->string('nom_prenom_pere')->nullable();
            $table->string('telephone2')->nullable(); // Père
            $table->string('nom_prenom_mere')->nullable();
            $table->string('telephone3')->nullable(); // Mère
            $table->string('information_tuteur')->nullable();
            $table->string('telephone4')->nullable(); // Tuteur
            $table->string('profession_tuteur')->nullable();
            $table->text('adresse_etudiant')->nullable();
            
            // Timestamps et SoftDeletes
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
        Schema::dropIfExists('etudiants');
    }
}
