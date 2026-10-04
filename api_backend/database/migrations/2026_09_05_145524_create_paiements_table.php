<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaiementsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            $table->foreignId('inscription_id')->nullable()->constrained('inscriptions')->onDelete('cascade');
            $table->string('type_paiement')->default('Frais Scolaire');
            
            $table->decimal('montant', 10, 2);
            $table->date('date_paiement');
            $table->string('mode_paiement'); // ex: 'Espece', 'MTN Mobile Money', 'Virement'
            $table->string('reference_transaction')->nullable(); // Pour MTN MoMo
            
            $table->string('statut')->default('complet'); // 'en_attente', 'complet', 'echoue'
            
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
        Schema::dropIfExists('paiements');
    }
}
