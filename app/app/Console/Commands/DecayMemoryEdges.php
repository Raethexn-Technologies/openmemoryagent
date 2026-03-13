<?php

namespace App\Console\Commands;

use App\Services\MemoryGraphService;
use Illuminate\Console\Command;

/**
 * Applies the Physarum decay term to all memory edges once per day.
 *
 * Edges that were not traversed since the last decay cycle lose 3 % of their weight.
 * Edges that sit at the floor (0.05) are left unchanged. The decay runs as a bulk
 * SQL update rather than per-record Eloquent operations so it scales with table size.
 *
 * Schedule this command to run daily in routes/console.php:
 *   Schedule::command('memory:decay')->daily();
 */
class DecayMemoryEdges extends Command
{
    protected $signature = 'memory:decay';

    protected $description = 'Apply Physarum-model weight decay to all memory graph edges.';

    public function handle(MemoryGraphService $graph): int
    {
        $this->info('Applying edge weight decay...');
        $graph->decay();
        $this->info('Edge decay complete.');

        return Command::SUCCESS;
    }
}
