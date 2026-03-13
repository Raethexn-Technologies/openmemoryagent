<?php

namespace Tests\Unit;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use App\Services\MemoryGraphService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemoryGraphServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_node_creates_similarity_and_same_sensitivity_anchor_edges(): void
    {
        $existing = MemoryNode::create([
            'user_id' => 'user-1',
            'session_id' => 'session-a',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Existing graph note',
            'content' => 'Existing graph note',
            'tags' => ['graph', 'laravel'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);

        $service = app(MemoryGraphService::class);

        $node = $service->storeNode('user-1', 'Alice is helping with Omega graph work.', [
            'type' => 'memory',
            'label' => 'Alice Omega work',
            'tags' => ['graph', 'testing'],
            'people' => ['Alice Johnson'],
            'projects' => ['Omega'],
            'sensitivity' => 'private',
        ], 'session-b');

        $tagEdge = MemoryEdge::where('relationship', 'same_topic_as')->first();
        $personAnchor = MemoryNode::where('type', 'person')->where('label', 'Alice Johnson')->first();
        $projectAnchor = MemoryNode::where('type', 'project')->where('label', 'Omega')->first();

        $this->assertNotNull($tagEdge);
        $this->assertSame($node->id, $tagEdge->from_node_id);
        $this->assertSame($existing->id, $tagEdge->to_node_id);
        $this->assertSame(0.3, $tagEdge->weight);
        $this->assertSame('private', $personAnchor?->sensitivity);
        $this->assertSame('private', $projectAnchor?->sensitivity);
        $this->assertDatabaseHas('memory_edges', [
            'from_node_id' => $node->id,
            'to_node_id' => $personAnchor->id,
            'relationship' => 'about_person',
        ]);
        $this->assertDatabaseHas('memory_edges', [
            'from_node_id' => $node->id,
            'to_node_id' => $projectAnchor->id,
            'relationship' => 'part_of',
        ]);
    }

    public function test_store_node_reuses_existing_anchor_nodes_with_matching_sensitivity(): void
    {
        $service = app(MemoryGraphService::class);

        $service->storeNode('user-1', 'Alice is reviewing the first milestone.', [
            'type' => 'memory',
            'label' => 'Alice milestone',
            'tags' => ['review'],
            'people' => ['Alice Johnson'],
            'projects' => [],
            'sensitivity' => 'public',
        ]);

        $service->storeNode('user-1', 'Alice is preparing the launch notes.', [
            'type' => 'memory',
            'label' => 'Alice launch notes',
            'tags' => ['launch'],
            'people' => ['Alice Johnson'],
            'projects' => [],
            'sensitivity' => 'public',
        ]);

        $this->assertSame(1, MemoryNode::where([
            'user_id' => 'user-1',
            'type' => 'person',
            'label' => 'Alice Johnson',
            'sensitivity' => 'public',
        ])->count());
    }

    public function test_get_graph_filters_nodes_and_edges_by_user_type_and_sensitivity(): void
    {
        $publicMemory = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Public memory',
            'content' => 'Public memory',
            'tags' => ['alpha'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $publicProject = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'project',
            'sensitivity' => 'public',
            'label' => 'Project node',
            'content' => 'Project node',
            'tags' => ['alpha'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $privateMemory = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'private',
            'label' => 'Private memory',
            'content' => 'Private memory',
            'tags' => ['beta'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $otherUserNode = MemoryNode::create([
            'user_id' => 'user-2',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Other user memory',
            'content' => 'Other user memory',
            'tags' => ['gamma'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);

        MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $publicMemory->id,
            'to_node_id' => $publicProject->id,
            'relationship' => 'part_of',
            'weight' => 0.8,
        ]);
        MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $publicMemory->id,
            'to_node_id' => $privateMemory->id,
            'relationship' => 'same_topic_as',
            'weight' => 0.4,
        ]);
        MemoryEdge::create([
            'user_id' => 'user-2',
            'from_node_id' => $otherUserNode->id,
            'to_node_id' => $otherUserNode->id,
            'relationship' => 'related_to',
            'weight' => 0.1,
        ]);

        $service = app(MemoryGraphService::class);

        $publicGraph = $service->getGraph('user-1');
        $memoryOnlyGraph = $service->getGraph('user-1', [
            'types' => ['memory'],
            'sensitivity' => ['public', 'private'],
        ]);

        $this->assertCount(2, $publicGraph['nodes']);
        $this->assertCount(1, $publicGraph['edges']);
        $publicNodeIds = collect($publicGraph['nodes'])->pluck('id')->all();
        sort($publicNodeIds);
        $expectedPublicNodeIds = [$publicMemory->id, $publicProject->id];
        sort($expectedPublicNodeIds);
        $this->assertSame($expectedPublicNodeIds, $publicNodeIds);
        $this->assertCount(2, $memoryOnlyGraph['nodes']);
        $this->assertCount(1, $memoryOnlyGraph['edges']);
        $memoryNodeIds = collect($memoryOnlyGraph['nodes'])->pluck('id')->all();
        sort($memoryNodeIds);
        $expectedMemoryNodeIds = [$publicMemory->id, $privateMemory->id];
        sort($expectedMemoryNodeIds);
        $this->assertSame($expectedMemoryNodeIds, $memoryNodeIds);
    }

    public function test_get_neighborhood_is_scoped_to_user_and_visible_sensitivity(): void
    {
        $root = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Root node',
            'content' => 'Root node',
            'tags' => ['root'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $privateNeighbor = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'private',
            'label' => 'Private node',
            'content' => 'Private node',
            'tags' => ['private'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $publicViaPrivate = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'project',
            'sensitivity' => 'public',
            'label' => 'Visible through private',
            'content' => 'Visible through private',
            'tags' => ['project'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);

        MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $root->id,
            'to_node_id' => $privateNeighbor->id,
            'relationship' => 'same_topic_as',
            'weight' => 0.6,
        ]);
        MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $privateNeighbor->id,
            'to_node_id' => $publicViaPrivate->id,
            'relationship' => 'part_of',
            'weight' => 0.7,
        ]);

        $service = app(MemoryGraphService::class);

        $publicNeighborhood = $service->getNeighborhood('user-1', $root->id, 2);
        $fullNeighborhood = $service->getNeighborhood('user-1', $root->id, 2, [
            'sensitivity' => ['public', 'private'],
        ]);

        $this->assertCount(1, $publicNeighborhood['nodes']);
        $this->assertCount(0, $publicNeighborhood['edges']);
        $this->assertCount(3, $fullNeighborhood['nodes']);
        $this->assertCount(2, $fullNeighborhood['edges']);

        $this->expectException(ModelNotFoundException::class);
        $service->getNeighborhood('user-2', $root->id, 1);
    }

    public function test_reinforce_updates_access_tracking_and_edge_weights_for_loaded_nodes(): void
    {
        $first = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'First',
            'content' => 'First',
            'tags' => ['alpha'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $second = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Second',
            'content' => 'Second',
            'tags' => ['beta'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $third = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Third',
            'content' => 'Third',
            'tags' => ['gamma'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);

        $reinforcedEdge = MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $first->id,
            'to_node_id' => $second->id,
            'relationship' => 'same_topic_as',
            'weight' => 0.5,
        ]);
        $untouchedEdge = MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $second->id,
            'to_node_id' => $third->id,
            'relationship' => 'same_topic_as',
            'weight' => 0.5,
        ]);

        $service = app(MemoryGraphService::class);

        $service->reinforce([$first->id, $second->id], 'user-1');

        $reinforcedEdge->refresh();
        $untouchedEdge->refresh();
        $first->refresh();
        $second->refresh();
        $third->refresh();

        $this->assertEqualsWithDelta(0.6, $reinforcedEdge->weight, 0.0001);
        $this->assertSame(1, $reinforcedEdge->access_count);
        $this->assertNotNull($reinforcedEdge->last_accessed_at);
        $this->assertEqualsWithDelta(0.5, $untouchedEdge->weight, 0.0001);
        $this->assertSame(0, $untouchedEdge->access_count);
        $this->assertSame(1, $first->access_count);
        $this->assertSame(1, $second->access_count);
        $this->assertSame(0, $third->access_count);
    }

    public function test_reinforce_tracks_single_node_access_without_touching_edges(): void
    {
        $node = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Solo',
            'content' => 'Solo',
            'tags' => ['solo'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $other = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Other',
            'content' => 'Other',
            'tags' => ['other'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $edge = MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $node->id,
            'to_node_id' => $other->id,
            'relationship' => 'same_topic_as',
            'weight' => 0.5,
        ]);

        $service = app(MemoryGraphService::class);

        $service->reinforce([$node->id], 'user-1');

        $node->refresh();
        $other->refresh();
        $edge->refresh();

        $this->assertSame(1, $node->access_count);
        $this->assertSame(0, $other->access_count);
        $this->assertSame(0, $edge->access_count);
        $this->assertNull($edge->last_accessed_at);
        $this->assertEqualsWithDelta(0.5, $edge->weight, 0.0001);
    }

    public function test_reinforce_from_memories_returns_matching_ids_and_ignores_unmatched_records(): void
    {
        $first = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'First',
            'content' => 'Remember alpha',
            'tags' => ['alpha'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $second = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Second',
            'content' => 'Remember beta',
            'tags' => ['beta'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);

        $edge = MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $first->id,
            'to_node_id' => $second->id,
            'relationship' => 'same_topic_as',
            'weight' => 0.5,
        ]);

        $service = app(MemoryGraphService::class);

        $nodeIds = $service->reinforceFromMemories([
            ['content' => 'Remember alpha'],
            ['content' => 'Remember beta'],
            ['content' => 'No matching graph node'],
        ], 'user-1');

        sort($nodeIds);
        $expectedIds = [$first->id, $second->id];
        sort($expectedIds);

        $edge->refresh();
        $this->assertSame($expectedIds, $nodeIds);
        $this->assertSame(1, $edge->access_count);
    }

    public function test_decay_reduces_weights_without_crossing_the_floor(): void
    {
        $first = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'First',
            'content' => 'First',
            'tags' => ['alpha'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);
        $second = MemoryNode::create([
            'user_id' => 'user-1',
            'type' => 'memory',
            'sensitivity' => 'public',
            'label' => 'Second',
            'content' => 'Second',
            'tags' => ['beta'],
            'confidence' => 1.0,
            'source' => 'chat',
        ]);

        $decaying = MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $first->id,
            'to_node_id' => $second->id,
            'relationship' => 'same_topic_as',
            'weight' => 0.5,
        ]);
        $floor = MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $second->id,
            'to_node_id' => $first->id,
            'relationship' => 'related_to',
            'weight' => 0.05,
        ]);

        $service = app(MemoryGraphService::class);

        $service->decay();

        $decaying->refresh();
        $floor->refresh();

        $this->assertEqualsWithDelta(0.485, $decaying->weight, 0.0001);
        $this->assertEqualsWithDelta(0.05, $floor->weight, 0.0001);
    }
}
