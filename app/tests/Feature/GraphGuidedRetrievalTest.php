<?php

namespace Tests\Feature;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use App\Services\MemoryGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphGuidedRetrievalTest extends TestCase
{
    use RefreshDatabase;

    private function makeNode(string $userId, string $content, string $label = '', float $weight = 0.0): MemoryNode
    {
        return MemoryNode::create([
            'user_id' => $userId,
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => $label ?: $content,
            'content' => $content,
            'tags' => [],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
    }

    public function test_retrieve_context_returns_empty_when_no_nodes_exist(): void
    {
        $service = app(MemoryGraphService::class);
        $result = $service->retrieveContext('user-empty');

        $this->assertSame([], $result);
    }

    public function test_retrieve_context_returns_empty_when_no_edges_exist(): void
    {
        // Nodes exist but no edges — findContextSeeds produces seeds but they
        // have weight 0 and the BFS finds no neighbours. Seeds are still returned.
        $this->makeNode('user-1', 'Isolated memory A');
        $this->makeNode('user-1', 'Isolated memory B');

        $service = app(MemoryGraphService::class);
        $result = $service->retrieveContext('user-1');

        // Seeds with zero edge weight are still returned; the method falls back to recency.
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('id', $result[0]);
        $this->assertArrayHasKey('content', $result[0]);
        $this->assertArrayHasKey('timestamp', $result[0]);
    }

    public function test_retrieve_context_returns_nodes_sorted_by_accumulated_edge_weight(): void
    {
        $hub = $this->makeNode('user-1', 'Hub memory');
        $leaf1 = $this->makeNode('user-1', 'Leaf one');
        $leaf2 = $this->makeNode('user-1', 'Leaf two');
        $leaf3 = $this->makeNode('user-1', 'Leaf three');

        // Hub has three edges with high weights; it should be selected as a seed.
        MemoryEdge::create(['user_id' => 'user-1', 'from_node_id' => $hub->id, 'to_node_id' => $leaf1->id, 'relationship' => 'related_to', 'weight' => 0.9]);
        MemoryEdge::create(['user_id' => 'user-1', 'from_node_id' => $hub->id, 'to_node_id' => $leaf2->id, 'relationship' => 'related_to', 'weight' => 0.8]);
        MemoryEdge::create(['user_id' => 'user-1', 'from_node_id' => $hub->id, 'to_node_id' => $leaf3->id, 'relationship' => 'related_to', 'weight' => 0.3]);

        $service = app(MemoryGraphService::class);
        $result = $service->retrieveContext('user-1');

        $returnedIds = array_column($result, 'id');
        $this->assertContains($hub->id, $returnedIds);
        $this->assertContains($leaf1->id, $returnedIds);
        $this->assertContains($leaf2->id, $returnedIds);
    }

    public function test_retrieve_context_respects_limit(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $nodes[] = $this->makeNode('user-limit', "Memory {$i}");
        }

        // Wire them all into a chain so BFS has plenty of neighbours to traverse.
        for ($i = 0; $i < 19; $i++) {
            MemoryEdge::create([
                'user_id' => 'user-limit',
                'from_node_id' => $nodes[$i]->id,
                'to_node_id' => $nodes[$i + 1]->id,
                'relationship' => 'related_to',
                'weight' => 0.5,
            ]);
        }

        $service = app(MemoryGraphService::class);
        $result = $service->retrieveContext('user-limit', 5);

        $this->assertLessThanOrEqual(5, count($result));
    }

    public function test_retrieve_context_preserves_edge_weight_order_when_expanding_neighbors(): void
    {
        $hub = $this->makeNode('user-order', 'Hub memory');
        $partnerOne = $this->makeNode('user-order', 'Partner one');
        $partnerTwo = $this->makeNode('user-order', 'Partner two');
        $partnerThree = $this->makeNode('user-order', 'Partner three');
        $weakNeighbor = $this->makeNode('user-order', 'Weak neighbor');
        $strongNeighbor = $this->makeNode('user-order', 'Strong neighbor');

        MemoryEdge::create(['user_id' => 'user-order', 'from_node_id' => $hub->id, 'to_node_id' => $partnerOne->id, 'relationship' => 'related_to', 'weight' => 1.0]);
        MemoryEdge::create(['user_id' => 'user-order', 'from_node_id' => $hub->id, 'to_node_id' => $partnerTwo->id, 'relationship' => 'related_to', 'weight' => 0.9]);
        MemoryEdge::create(['user_id' => 'user-order', 'from_node_id' => $hub->id, 'to_node_id' => $partnerThree->id, 'relationship' => 'related_to', 'weight' => 0.8]);
        MemoryEdge::create(['user_id' => 'user-order', 'from_node_id' => $hub->id, 'to_node_id' => $strongNeighbor->id, 'relationship' => 'related_to', 'weight' => 0.7]);
        MemoryEdge::create(['user_id' => 'user-order', 'from_node_id' => $hub->id, 'to_node_id' => $weakNeighbor->id, 'relationship' => 'related_to', 'weight' => 0.2]);

        $service = app(MemoryGraphService::class);
        $result = $service->retrieveContext('user-order', 6);

        $orderedContents = array_column($result, 'content');
        $strongIndex = array_search('Strong neighbor', $orderedContents, true);
        $weakIndex = array_search('Weak neighbor', $orderedContents, true);

        $this->assertIsInt($strongIndex);
        $this->assertIsInt($weakIndex);
        $this->assertLessThan($weakIndex, $strongIndex);
    }

    public function test_retrieve_context_excludes_private_nodes(): void
    {
        $public = $this->makeNode('user-priv', 'Public memory');
        $private = MemoryNode::create([
            'user_id' => 'user-priv',
            'type' => 'memory',
            'sensitivity' => 'private',
            'label' => 'Private memory',
            'content' => 'Private memory',
            'tags' => [],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);

        MemoryEdge::create([
            'user_id' => 'user-priv',
            'from_node_id' => $public->id,
            'to_node_id' => $private->id,
            'relationship' => 'related_to',
            'weight' => 0.9,
        ]);

        $service = app(MemoryGraphService::class);
        $result = $service->retrieveContext('user-priv');

        $returnedIds = array_column($result, 'id');
        $this->assertContains($public->id, $returnedIds);
        $this->assertNotContains($private->id, $returnedIds);
    }

    public function test_retrieve_context_does_not_cross_user_boundaries(): void
    {
        $userA = $this->makeNode('user-a', 'User A memory');
        $userB = $this->makeNode('user-b', 'User B memory');

        MemoryEdge::create([
            'user_id' => 'user-a',
            'from_node_id' => $userA->id,
            'to_node_id' => $userA->id,
            'relationship' => 'related_to',
            'weight' => 0.8,
        ]);

        $service = app(MemoryGraphService::class);
        $result = $service->retrieveContext('user-a');

        $returnedIds = array_column($result, 'id');
        $this->assertNotContains($userB->id, $returnedIds);
    }

    public function test_retrieve_context_returns_content_and_timestamp_fields(): void
    {
        $node = $this->makeNode('user-fields', 'Test content string');
        MemoryEdge::create([
            'user_id' => 'user-fields',
            'from_node_id' => $node->id,
            'to_node_id' => $node->id,
            'relationship' => 'related_to',
            'weight' => 0.5,
        ]);

        $service = app(MemoryGraphService::class);
        $result = $service->retrieveContext('user-fields');

        $this->assertNotEmpty($result);
        $record = $result[0];
        $this->assertArrayHasKey('id', $record);
        $this->assertArrayHasKey('content', $record);
        $this->assertArrayHasKey('timestamp', $record);
        $this->assertSame('Test content string', $record['content']);
    }

    public function test_reinforce_increments_only_edges_between_retrieved_nodes(): void
    {
        $a = $this->makeNode('user-reinforce', 'Node A');
        $b = $this->makeNode('user-reinforce', 'Node B');
        $c = $this->makeNode('user-reinforce', 'Node C');

        $abEdge = MemoryEdge::create(['user_id' => 'user-reinforce', 'from_node_id' => $a->id, 'to_node_id' => $b->id, 'relationship' => 'related_to', 'weight' => 0.5]);
        $bcEdge = MemoryEdge::create(['user_id' => 'user-reinforce', 'from_node_id' => $b->id, 'to_node_id' => $c->id, 'relationship' => 'related_to', 'weight' => 0.5]);

        $service = app(MemoryGraphService::class);
        // Reinforce only A and B — the B→C edge should not be incremented.
        $service->reinforce([$a->id, $b->id], 'user-reinforce');

        $abEdge->refresh();
        $bcEdge->refresh();

        $this->assertEqualsWithDelta(0.6, $abEdge->weight, 0.0001);
        $this->assertEqualsWithDelta(0.5, $bcEdge->weight, 0.0001);
    }
}
