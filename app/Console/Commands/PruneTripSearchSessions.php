<?php

namespace App\Console\Commands;

use App\Services\TripSearchSessionStore;
use Illuminate\Console\Command;

class PruneTripSearchSessions extends Command
{
    protected $signature = 'trip-search-sessions:prune';

    protected $description = 'Delete expired trip search pagination snapshots.';

    public function handle(TripSearchSessionStore $searchSessions): int
    {
        $deleted = $searchSessions->pruneExpired();

        $this->info("Pruned {$deleted} expired trip search session".($deleted === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
