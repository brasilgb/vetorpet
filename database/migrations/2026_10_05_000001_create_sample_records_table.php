<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro dos dados fictícios (dados de exemplo) criados para um tenant
     * recém-cadastrado. A exclusão só remove o que estiver listado aqui, nunca
     * registros reais (ver App\Services\SampleData\SampleDataService).
     */
    public function up(): void
    {
        Schema::create('sample_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('record_type', 40);
            $table->unsignedBigInteger('record_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'record_type', 'record_id']);
            $table->index(['record_type', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_records');
    }
};
