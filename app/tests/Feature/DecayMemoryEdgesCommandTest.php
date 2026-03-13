<?php

namespace Tests\Feature;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecayMemoryEdgesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_memory_decay_command_applies_edge_decay(): void
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

        $edge = MemoryEdge::create([
            'user_id' => 'user-1',
            'from_node_id' => $first->id,
            'to_node_id' => $second->id,
            'relationship' => 'same_topic_as',
            'weight' => 0.5,
        ]);

        $this->artisan('memory:decay')
            ->expectsOutput('Applying edge weight decay...')
            ->expectsOutput('Edge decay complete.')
            ->assertExitCode(0);

        $edge->refresh();

        $this->assertEqualsWithDelta(0.485, $edge->weight, 0.0001);
    }
}
