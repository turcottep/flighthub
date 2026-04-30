<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 32);
            $table->string('search_hash', 64)->index();
            $table->json('params');
            $table->unsignedInteger('result_count');
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();
        });

        Schema::create('search_session_results', function (Blueprint $table): void {
            $table->uuid('search_session_id');
            $table->unsignedInteger('position');
            $table->json('itinerary');

            $table->primary(['search_session_id', 'position']);
            $table->foreign('search_session_id')
                ->references('id')
                ->on('search_sessions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_session_results');
        Schema::dropIfExists('search_sessions');
    }
};
