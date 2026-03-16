<?php

namespace App\Console\Commands;

use App\Models\MemoryNode;
use App\Services\ConsolidationService;
use Illuminate\Console\Command;

/**
 * Consolidate dense episodic memory clusters into semantic concept nodes.
 *
 * Scans the memory graph for clusters with mean internal edge weight >= 0.30
 * and at least 5 unconsolidated nodes. Each qualifying cluster is summarised
 * by the LLM into a single 'concept' node, and the originals are marked
 * consolidated_at so they are excluded from future retrieval and consolidation.
 *
 * Run on a schedule (e.g. weekly) or trigger via the in-app button:
 *   POST /api/graph/consolidate
 */
class ConsolidateMemory extends Command
{
    protected $signature = 'memory:consolidate
                            {--user= : Limit to a specific user_id (runs all users if omitted)}';

    protected $description = 'Consolidate dense episodic clusters into semantic concept nodes';

    public function handle(ConsolidationService $consolidator): int
    {
        $specificUser = $this->option('user');

        $userIds = $specificUser
            ? [$specificUser]
            : MemoryNode::whereNull('consolidated_at')
                ->distinct()
                ->pluck('user_id')
                ->all();

        if (empty($userIds)) {
            $this->info('No users with unconsolidated memory nodes.');

            return self::SUCCESS;
        }

        $totalClusters = 0;
        $totalNodes = 0;
        $totalConcepts = 0;

        foreach ($userIds as $userId) {
            $result = $consolidator->consolidate($userId);

            if ($result['clusters_consolidated'] > 0) {
                $this->line(sprintf(
                    'User %s: consolidated %d cluster(s), %d node(s) → %d concept(s)',
                    mb_substr($userId, 0, 16).'…',
                    $result['clusters_consolidated'],
                    $result['nodes_consolidated'],
                    $result['concept_nodes_created'],
                ));
            }

            $totalClusters += $result['clusters_consolidated'];
            $totalNodes    += $result['nodes_consolidated'];
            $totalConcepts += $result['concept_nodes_created'];
        }

        $this->info(sprintf(
            'Done. %d cluster(s) consolidated, %d episodic node(s) → %d concept node(s).',
            $totalClusters,
            $totalNodes,
            $totalConcepts,
        ));

        return self::SUCCESS;
    }
}
