<?php

namespace Tests\Feature;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Correctness tests for the PruneMemoryNodes Artisan command.
 *
 * A node is pruned when BOTH conditions hold:
 *   1. All of its edges have decayed to floor weight (<= 0.06)
 *   2. The node has not been accessed in the last 90 days (or was never accessed
 *      and is older than 90 days)
 *
 * Nodes with any edge above 0.06 are still active in the Physarum model
 * and must never be pruned regardless of last_accessed_at.
 *
 * Pruning is a hard delete: both the nodes and their edges are removed.
 */
class PruneMemoryNodesCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = Str::uuid()->toString();
    }

    private function makeNode(array $overrides = []): MemoryNode
    {
        $attributes = array_merge([
            'user_id'          => $this->userId,
            'type'             => 'memory',
            'sensitivity'      => 'public',
            'label'            => 'Test node',
            'content'          => 'Test content.',
            'tags'             => [],
            'confidence'       => 1.0,
            'source'           => 'chat',
            'last_accessed_at' => now()->subDays(100),
        ], $overrides);

        $node = MemoryNode::create($attributes);

        // Eloquent manages created_at automatically and ignores it in mass assignment.
        // Use a raw DB update so the pruning idle-age check sees the correct timestamp.
        $createdAt = $overrides['created_at'] ?? now()->subDays(100);
        DB::table('memory_nodes')->where('id', $node->id)->update(['created_at' => $createdAt]);

        return $node->fresh();
    }

    private function makeEdge(MemoryNode $from, MemoryNode $to, float $weight): MemoryEdge
    {
        return MemoryEdge::create([
            'user_id'      => $this->userId,
            'from_node_id' => $from->id,
            'to_node_id'   => $to->id,
            'relationship' => 'same_topic_as',
            'weight'       => $weight,
        ]);
    }

    // ── Nodes that must NOT be pruned ─────────────────────────────────────────

    public function test_node_with_active_edge_above_floor_is_not_pruned(): void
    {
        $a = $this->makeNode();
        $b = $this->makeNode();
        $this->makeEdge($a, $b, 0.5); // well above floor — still active

        $this->artisan('memory:prune')->assertSuccessful();

        $this->assertDatabaseHas('memory_nodes', ['id' => $a->id]);
        $this->assertDatabaseHas('memory_nodes', ['id' => $b->id]);
    }

    public function test_node_accessed_within_90_days_is_not_pruned(): void
    {
        $a = $this->makeNode(['last_accessed_at' => now()->subDays(30)]);
        $b = $this->makeNode(['last_accessed_at' => now()->subDays(30)]);
        $this->makeEdge($a, $b, 0.05); // at floor

        $this->artisan('memory:prune')->assertSuccessful();

        $this->assertDatabaseHas('memory_nodes', ['id' => $a->id]);
        $this->assertDatabaseHas('memory_nodes', ['id' => $b->id]);
    }

    // ── Nodes that MUST be pruned ─────────────────────────────────────────────

    public function test_node_with_all_floor_edges_idle_90_days_is_pruned(): void
    {
        $a = $this->makeNode(); // last_accessed_at 100 days ago
        $b = $this->makeNode();
        $edge = $this->makeEdge($a, $b, 0.05); // at floor

        $this->artisan('memory:prune')->assertSuccessful();

        $this->assertDatabaseMissing('memory_nodes', ['id' => $a->id]);
        $this->assertDatabaseMissing('memory_nodes', ['id' => $b->id]);
        $this->assertDatabaseMissing('memory_edges', ['id' => $edge->id]);
    }

    public function test_isolated_node_older_than_90_days_with_no_edges_is_pruned(): void
    {
        $isolated = $this->makeNode([
            'last_accessed_at' => null,
            'created_at'       => now()->subDays(100),
        ]);

        $this->artisan('memory:prune')->assertSuccessful();

        $this->assertDatabaseMissing('memory_nodes', ['id' => $isolated->id]);
    }

    public function test_edges_belonging_to_pruned_nodes_are_also_deleted(): void
    {
        $a = $this->makeNode();
        $b = $this->makeNode();
        $c = $this->makeNode();

        $edgeAB = $this->makeEdge($a, $b, 0.05);
        $edgeBC = $this->makeEdge($b, $c, 0.05);

        $this->artisan('memory:prune')->assertSuccessful();

        $this->assertDatabaseMissing('memory_edges', ['id' => $edgeAB->id]);
        $this->assertDatabaseMissing('memory_edges', ['id' => $edgeBC->id]);
    }

    // ── Mixed scenarios ───────────────────────────────────────────────────────

    public function test_prune_only_deletes_dormant_nodes_leaves_active_ones(): void
    {
        // Active: accessed recently, edge above floor.
        $activeA = $this->makeNode(['last_accessed_at' => now()->subDays(10)]);
        $activeB = $this->makeNode(['last_accessed_at' => now()->subDays(10)]);
        $this->makeEdge($activeA, $activeB, 0.7);

        // Dormant: idle 100 days, floor-weight edge.
        $dormantA = $this->makeNode();
        $dormantB = $this->makeNode();
        $dormantEdge = $this->makeEdge($dormantA, $dormantB, 0.05);

        $this->artisan('memory:prune')->assertSuccessful();

        $this->assertDatabaseHas('memory_nodes', ['id' => $activeA->id]);
        $this->assertDatabaseHas('memory_nodes', ['id' => $activeB->id]);
        $this->assertDatabaseMissing('memory_nodes', ['id' => $dormantA->id]);
        $this->assertDatabaseMissing('memory_nodes', ['id' => $dormantB->id]);
        $this->assertDatabaseMissing('memory_edges', ['id' => $dormantEdge->id]);
    }

    public function test_dry_run_reports_count_without_deleting(): void
    {
        $a = $this->makeNode();
        $b = $this->makeNode();
        $this->makeEdge($a, $b, 0.05);

        $this->artisan('memory:prune', ['--dry-run' => true])->assertSuccessful();

        // Both nodes must still exist after a dry run.
        $this->assertDatabaseHas('memory_nodes', ['id' => $a->id]);
        $this->assertDatabaseHas('memory_nodes', ['id' => $b->id]);
    }

    public function test_user_scoping_limits_pruning_to_specified_user(): void
    {
        // Dormant node for userId.
        $target = $this->makeNode();
        $partner = $this->makeNode();
        $this->makeEdge($target, $partner, 0.05);

        // Dormant node for a different user — must NOT be pruned when we scope to userId.
        $otherUser = 'different-user';
        $other = MemoryNode::create([
            'user_id'          => $otherUser,
            'type'             => 'memory',
            'sensitivity'      => 'public',
            'label'            => 'Other user node',
            'content'          => 'Should survive.',
            'tags'             => [],
            'confidence'       => 1.0,
            'source'           => 'chat',
            'last_accessed_at' => now()->subDays(100),
            'created_at'       => now()->subDays(100),
        ]);

        $this->artisan('memory:prune', ['--user' => $this->userId])->assertSuccessful();

        $this->assertDatabaseMissing('memory_nodes', ['id' => $target->id]);
        // The other user's node must still exist — --user scoping is active.
        $this->assertNotNull(MemoryNode::find($other->id),
            'Nodes belonging to other users must not be affected when --user scoping is active.');
    }
}
