<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Apply Physarum decay to memory graph edges once per day.
// Edges that were not traversed during the preceding day lose 3 % of their weight.
// See MemoryGraphService::decay() and DEVLOG Entry 003 for the mathematical basis.
Schedule::command('memory:decay')->daily();

// Capture a cluster-level snapshot of every user's memory graph every 15 minutes.
// Snapshots feed the Three.js mission control temporal axis so operators can scrub
// back through collective graph state during long multi-agent runs.
// See TakeGraphSnapshot and DEVLOG Entry 011 for the design rationale.
Schedule::command('graph:snapshot')->everyFifteenMinutes();

// Compress dense episodic clusters into semantic concept nodes once per week.
// Qualifying clusters: mean internal edge weight >= 0.30, size >= 5 unconsolidated nodes.
// See ConsolidationService and DEVLOG Entry 019 for the design rationale.
Schedule::command('memory:consolidate')->weekly();

// Prune dormant nodes whose every edge has decayed to floor weight.
// Nodes idle for 90 days with no active edges are hard-deleted along with their edges.
// Run monthly so the daily decay pass has time to identify truly dormant nodes.
// See PruneMemoryNodes and DEVLOG Entry 019 for the design rationale.
Schedule::command('memory:prune')->monthly();
