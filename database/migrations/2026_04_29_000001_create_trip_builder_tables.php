<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airlines', function (Blueprint $table): void {
            $table->string('code', 3)->primary();
            $table->string('name');
        });

        Schema::create('airports', function (Blueprint $table): void {
            $table->string('code', 3)->primary();
            $table->string('city_code', 3)->index();
            $table->string('name');
            $table->string('city');
            $table->string('country_code', 2)->index();
            $table->string('region_code', 16)->nullable()->index();
            $table->double('latitude');
            $table->double('longitude');
            $table->string('timezone');
        });

        Schema::create('flights', function (Blueprint $table): void {
            $table->id();
            $table->string('airline_code', 3);
            $table->string('number', 8);
            $table->string('departure_airport_code', 3);
            $table->time('departure_time');
            $table->string('arrival_airport_code', 3);
            $table->time('arrival_time');
            $table->decimal('price', 10, 2);

            $table->foreign('airline_code')->references('code')->on('airlines')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('departure_airport_code')->references('code')->on('airports')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('arrival_airport_code')->references('code')->on('airports')->cascadeOnUpdate()->restrictOnDelete();

            $table->unique(['airline_code', 'number']);
            $table->index('airline_code');
            $table->index('departure_airport_code');
            $table->index(['departure_airport_code', 'arrival_airport_code']);
        });

        Schema::create('trips', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 32);
            $table->timestampsTz();
        });

        Schema::create('trip_flights', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trip_id')->constrained('trips')->cascadeOnDelete();
            $table->foreignId('flight_id')->constrained('flights')->restrictOnDelete();
            $table->date('departure_date');
            $table->unsignedTinyInteger('segment_order');

            $table->unique(['trip_id', 'segment_order']);
            $table->index('flight_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_flights');
        Schema::dropIfExists('trips');
        Schema::dropIfExists('flights');
        Schema::dropIfExists('airports');
        Schema::dropIfExists('airlines');
    }
};
