<?php

namespace Tests\Feature;

use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use App\Services\GraphExtractionService;
use App\Services\IcpMemoryService;
use App\Services\LLM\LlmService;
use App\Services\MemorySummarizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class ChatMemoryGraphTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_public_mock_memory_is_written_and_synced_during_send(): void
    {
        $this->bindLlmForSend();

        $icp = Mockery::mock(IcpMemoryService::class);
        $icp->shouldIgnoreMissing();
        $icp->shouldReceive('getPublicMemories')->once()->with('user-1')->andReturn([]);
        $icp->shouldReceive('isMockMode')->once()->andReturn(true);
        $icp->shouldReceive('storeMemory')->once()->andReturn('mem-1');
        $icp->shouldReceive('mode')->andReturn('mock');
        $this->app->instance(IcpMemoryService::class, $icp);

        $summarizer = Mockery::mock(MemorySummarizationService::class);
        $summarizer->shouldReceive('extract')->once()->andReturn([
            'content' => 'User builds Laravel graph tools.',
            'type' => 'public',
        ]);
        $this->app->instance(MemorySummarizationService::class, $summarizer);

        $graphExtractor = Mockery::mock(GraphExtractionService::class);
        $graphExtractor->shouldReceive('extract')->once()->with('User builds Laravel graph tools.', 'public')->andReturn([
            'type' => 'memory',
            'label' => 'Laravel graph tools',
            'tags' => ['laravel', 'graph'],
            'people' => [],
            'projects' => [],
            'sensitivity' => 'public',
        ]);
        $this->app->instance(GraphExtractionService::class, $graphExtractor);

        $response = $this->withSession([
            'chat_session_id' => 'session-1',
            'chat_user_id' => 'user-1',
        ])->postJson('/chat/send', [
            'message' => 'I build graph tools with Laravel.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('memory_id', 'mem-1');
        $response->assertJsonPath('memory_type', 'public');
        $response->assertJsonPath('active_node_ids', []);
        $this->assertDatabaseCount('memory_nodes', 1);
        $this->assertDatabaseHas('memory_nodes', [
            'user_id' => 'user-1',
            'label' => 'Laravel graph tools',
            'sensitivity' => 'public',
        ]);
    }

    public function test_private_mock_memory_waits_for_approval_before_graph_sync(): void
    {
        $this->bindLlmForSend();

        $icp = Mockery::mock(IcpMemoryService::class);
        $icp->shouldIgnoreMissing();
        $icp->shouldReceive('getPublicMemories')->once()->with('user-1')->andReturn([]);
        $icp->shouldReceive('isMockMode')->twice()->andReturn(true);
        $icp->shouldReceive('mode')->andReturn('mock');
        $icp->shouldReceive('mockStoreApproved')->once()->andReturn('mem-private-1');
        $this->app->instance(IcpMemoryService::class, $icp);

        $summarizer = Mockery::mock(MemorySummarizationService::class);
        $summarizer->shouldReceive('extract')->once()->andReturn([
            'content' => 'User wants private launch notes saved.',
            'type' => 'private',
        ]);
        $this->app->instance(MemorySummarizationService::class, $summarizer);

        $graphExtractor = Mockery::mock(GraphExtractionService::class);
        $graphExtractor->shouldReceive('extract')->once()->with('User wants private launch notes saved.', 'private')->andReturn([
            'type' => 'task',
            'label' => 'Private launch notes',
            'tags' => ['launch', 'notes'],
            'people' => [],
            'projects' => [],
            'sensitivity' => 'private',
        ]);
        $this->app->instance(GraphExtractionService::class, $graphExtractor);

        $sendResponse = $this->withSession([
            'chat_session_id' => 'session-1',
            'chat_user_id' => 'user-1',
        ])->postJson('/chat/send', [
            'message' => 'Remember my launch notes privately.',
        ]);

        $sendResponse->assertOk();
        $sendResponse->assertJsonPath('memory_type', 'private');
        $this->assertDatabaseCount('memory_nodes', 0);

        $storeResponse = $this->withSession([
            'chat_session_id' => 'session-1',
            'chat_user_id' => 'user-1',
        ])->postJson('/chat/store-memory', [
            'content' => 'User wants private launch notes saved.',
            'memory_type' => 'private',
        ]);

        $storeResponse->assertOk();
        $storeResponse->assertJsonPath('id', 'mem-private-1');
        $this->assertDatabaseCount('memory_nodes', 1);
        $this->assertDatabaseHas('memory_nodes', [
            'user_id' => 'user-1',
            'label' => 'Private launch notes',
            'sensitivity' => 'private',
        ]);
    }

    public function test_live_sync_endpoint_adds_graph_node_after_browser_write(): void
    {
        $this->bindUnusedControllerDependencies();

        $graphExtractor = Mockery::mock(GraphExtractionService::class);
        $graphExtractor->shouldReceive('extract')->once()->with('Browser-signed memory.', 'public')->andReturn([
            'type' => 'memory',
            'label' => 'Browser memory',
            'tags' => ['browser'],
            'people' => [],
            'projects' => [],
            'sensitivity' => 'public',
        ]);
        $this->app->instance(GraphExtractionService::class, $graphExtractor);

        $response = $this->withSession([
            'chat_session_id' => 'session-live',
            'chat_user_id' => 'user-live',
        ])->postJson('/chat/sync-graph-memory', [
            'content' => 'Browser-signed memory.',
            'memory_type' => 'public',
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $this->assertDatabaseHas('memory_nodes', [
            'user_id' => 'user-live',
            'label' => 'Browser memory',
            'sensitivity' => 'public',
        ]);
    }

    public function test_send_uses_graph_guided_retrieval_when_graph_has_edges(): void
    {
        // With graph nodes and edges present, the new flow calls retrieveContext() directly.
        // getPublicMemories() is never called — the graph is the primary retrieval source.
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

        $this->bindLlmForSend();

        // ICP is NOT consulted for memories when the graph has nodes with edges.
        $icp = Mockery::mock(IcpMemoryService::class);
        $icp->shouldIgnoreMissing();
        $icp->shouldNotReceive('getPublicMemories');
        $icp->shouldReceive('mode')->andReturn('mock');
        $this->app->instance(IcpMemoryService::class, $icp);

        $summarizer = Mockery::mock(MemorySummarizationService::class);
        $summarizer->shouldReceive('extract')->once()->andReturn(null);
        $this->app->instance(MemorySummarizationService::class, $summarizer);

        $response = $this->withSession([
            'chat_session_id' => 'session-1',
            'chat_user_id' => 'user-1',
        ])->postJson('/chat/send', [
            'message' => 'What do you remember about me?',
        ]);

        $response->assertOk();
        $activeNodeIds = $response->json('active_node_ids');
        sort($activeNodeIds);
        $expectedIds = [$first->id, $second->id];
        sort($expectedIds);

        $edge->refresh();

        $this->assertSame($expectedIds, $activeNodeIds);
        // The edge between the two co-retrieved nodes is reinforced by ALPHA = 0.10.
        $this->assertEqualsWithDelta(0.6, $edge->weight, 0.0001);
    }

    public function test_send_falls_back_to_icp_when_graph_is_empty(): void
    {
        // Cold start: no graph nodes exist. The system falls back to flat ICP recall.
        $this->bindLlmForSend();

        $icp = Mockery::mock(IcpMemoryService::class);
        $icp->shouldIgnoreMissing();
        $icp->shouldReceive('getPublicMemories')->once()->with('user-cold')->andReturn([
            ['content' => 'Cold start memory', 'timestamp' => now()->toIso8601String(), 'memory_type' => 'public'],
        ]);
        $icp->shouldReceive('isMockMode')->andReturn(true);
        $icp->shouldReceive('mode')->andReturn('mock');
        $this->app->instance(IcpMemoryService::class, $icp);

        $summarizer = Mockery::mock(MemorySummarizationService::class);
        $summarizer->shouldReceive('extract')->once()->andReturn(null);
        $this->app->instance(MemorySummarizationService::class, $summarizer);

        $response = $this->withSession([
            'chat_session_id' => 'session-cold',
            'chat_user_id' => 'user-cold',
        ])->postJson('/chat/send', [
            'message' => 'Hello.',
        ]);

        $response->assertOk();
        // No graph nodes, so active_node_ids is empty.
        $this->assertSame([], $response->json('active_node_ids'));
    }

    private function bindLlmForSend(): void
    {
        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('buildSystemPrompt')->once()->andReturn('system prompt');
        $llm->shouldReceive('chat')->once()->andReturn('assistant reply');
        $llm->shouldReceive('provider')->andReturn('test-provider');
        $this->app->instance(LlmService::class, $llm);
    }

    private function bindUnusedControllerDependencies(): void
    {
        $llm = Mockery::mock(LlmService::class);
        $llm->shouldIgnoreMissing();
        $this->app->instance(LlmService::class, $llm);

        $icp = Mockery::mock(IcpMemoryService::class);
        $icp->shouldIgnoreMissing();
        $this->app->instance(IcpMemoryService::class, $icp);

        $summarizer = Mockery::mock(MemorySummarizationService::class);
        $summarizer->shouldIgnoreMissing();
        $this->app->instance(MemorySummarizationService::class, $summarizer);
    }
}
