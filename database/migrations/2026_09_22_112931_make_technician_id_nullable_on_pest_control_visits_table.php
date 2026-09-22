<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Visita sem técnico pré-atribuído: o atendimento passa a ser direcionado
     * pessoalmente (qualquer técnico do tenant pode assumir), e o
     * technician_id só é preenchido no check-in (ver PestControlVisitService).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE pest_control_visits MODIFY technician_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pest_control_visits MODIFY technician_id BIGINT UNSIGNED NOT NULL');
    }
};
