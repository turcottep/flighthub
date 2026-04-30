<?php

use App\Http\Controllers\FlightReferenceController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\TripSearchController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');
Route::get('/health', HealthController::class);

Route::prefix('api')->group(function (): void {
    Route::get('/airlines', [FlightReferenceController::class, 'airlines']);
    Route::get('/airports', [FlightReferenceController::class, 'airports']);
    Route::get('/trips/search/one-way', [TripSearchController::class, 'oneWay']);
    Route::get('/trips/search/one-way-nearby', [TripSearchController::class, 'oneWayNearby']);
    Route::get('/trips/search/round-trip', [TripSearchController::class, 'roundTrip']);
    Route::get('/trips/search/open-jaw', [TripSearchController::class, 'openJaw']);
    Route::get('/trips/search/multi-city', [TripSearchController::class, 'multiCity']);
});

if (app()->environment('testing')) {
    Route::post('/__e2e/expire-search-session/{searchSession}', function (string $searchSession) {
        DB::table('search_sessions')
            ->where('id', $searchSession)
            ->update(['expires_at' => now()->subMinute()]);

        return response()->noContent();
    });
}
