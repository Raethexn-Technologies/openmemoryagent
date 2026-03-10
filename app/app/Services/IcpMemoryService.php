<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Connects to the ICP memory canister via HTTP.
 *
 * For demo purposes this uses the canister's HTTP gateway endpoint.
 * In local dev, dfx serves the canister at http://localhost:4943.
 *
 * The canister exposes Candid-over-HTTP or we use a thin Node adapter.
 * See docs/ICP_INTEGRATION.md for details.
 */
class IcpMemoryService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.icp.endpoint', 'http://localhost:4943'), '/');
    }

    /**
     * Store a memory record in the ICP canister.
     */
    public function storeMemory(string $userId, string $sessionId, string $content, ?string $metadata = null): string
    {
        if ($this->isMockMode()) {
            return $this->mockStore($userId, $sessionId, $content, $metadata);
        }

        $response = Http::timeout(10)->post("{$this->baseUrl}/store", [
            'user_id'    => $userId,
            'session_id' => $sessionId,
            'content'    => $content,
            'metadata'   => $metadata,
        ]);

        if ($response->failed()) {
            Log::error('ICP storeMemory failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RuntimeException('ICP canister store failed: ' . $response->body());
        }

        return $response->json('id', 'unknown');
    }

    /**
     * Retrieve all memory records for a user from the ICP canister.
     *
     * @return array<int, array{id: string, user_id: string, session_id: string, content: string, timestamp: string, metadata: string|null}>
     */
    public function getMemories(string $userId): array
    {
        if ($this->isMockMode()) {
            return $this->mockGet($userId);
        }

        $response = Http::timeout(10)->get("{$this->baseUrl}/memories/{$userId}");

        if ($response->failed()) {
            Log::warning('ICP getMemories failed', ['user_id' => $userId]);
            return [];
        }

        return $response->json('memories', []);
    }

    /**
     * Retrieve memories for a specific session.
     *
     * @return array
     */
    public function getMemoriesBySession(string $sessionId): array
    {
        if ($this->isMockMode()) {
            return [];
        }

        $response = Http::timeout(10)->get("{$this->baseUrl}/memories/session/{$sessionId}");

        if ($response->failed()) {
            return [];
        }

        return $response->json('memories', []);
    }

    /**
     * List recent memories across all users (for inspector dashboard).
     */
    public function listRecentMemories(int $limit = 20): array
    {
        if ($this->isMockMode()) {
            return $this->mockListRecent($limit);
        }

        $response = Http::timeout(10)->get("{$this->baseUrl}/memories/recent", ['limit' => $limit]);

        if ($response->failed()) {
            return [];
        }

        return $response->json('memories', []);
    }

    private function isMockMode(): bool
    {
        return config('services.icp.mock', true);
    }

    // ---------------------------------------------------------------------------
    // Mock implementations for local dev without a running dfx canister
    // ---------------------------------------------------------------------------

    private array $mockStorage = [];

    private function mockStore(string $userId, string $sessionId, string $content, ?string $metadata): string
    {
        $id = $userId . ':' . time() . ':' . rand(1000, 9999);
        $this->mockStorage[] = [
            'id'         => $id,
            'user_id'    => $userId,
            'session_id' => $sessionId,
            'content'    => $content,
            'timestamp'  => now()->toIso8601String(),
            'metadata'   => $metadata,
        ];
        // Persist mock data in session/cache for demo continuity
        $existing = cache()->get("mock_icp_{$userId}", []);
        $existing[] = end($this->mockStorage);
        cache()->put("mock_icp_{$userId}", $existing, now()->addHours(2));
        cache()->put('mock_icp_recent', array_merge(
            cache()->get('mock_icp_recent', []),
            [end($this->mockStorage)]
        ), now()->addHours(2));

        return $id;
    }

    private function mockGet(string $userId): array
    {
        return cache()->get("mock_icp_{$userId}", []);
    }

    private function mockListRecent(int $limit): array
    {
        return array_slice(cache()->get('mock_icp_recent', []), -$limit);
    }
}
