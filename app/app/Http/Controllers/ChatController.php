<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\Message;
use App\Services\IcpMemoryService;
use App\Services\LLM\LlmService;
use App\Services\MemorySummarizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function __construct(
        private readonly LlmService $llm,
        private readonly IcpMemoryService $icp,
        private readonly MemorySummarizationService $summarizer,
    ) {}

    /**
     * Show the chat UI.
     */
    public function index(): Response
    {
        $sessionId = session()->get('chat_session_id', (string) Str::uuid());
        session()->put('chat_session_id', $sessionId);

        $userId = session()->get('chat_user_id', 'user_' . Str::random(8));
        session()->put('chat_user_id', $userId);

        $messages = Message::where('session_id', $sessionId)
            ->orderBy('created_at')
            ->get(['role', 'content', 'created_at'])
            ->toArray();

        return Inertia::render('Chat/Index', [
            'session_id'   => $sessionId,
            'user_id'      => $userId,
            'messages'     => $messages,
            'llm_provider' => $this->llm->provider(),
        ]);
    }

    /**
     * Handle a new chat message.
     */
    public function send(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $sessionId = session()->get('chat_session_id');
        $userId    = session()->get('chat_user_id');

        if (! $sessionId || ! $userId) {
            return response()->json(['error' => 'Session not found. Please refresh.'], 422);
        }

        // Persist user message
        Message::create([
            'session_id' => $sessionId,
            'role'       => 'user',
            'content'    => $validated['message'],
        ]);

        // Retrieve existing memories from ICP
        $memories = $this->icp->getMemories($userId);

        // Build prompt with memory context
        $systemPrompt = $this->llm->buildSystemPrompt($memories);

        // Get recent conversation history for context
        $history = Message::where('session_id', $sessionId)
            ->orderBy('created_at')
            ->get(['role', 'content'])
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();

        // Generate AI response
        $aiResponse = $this->llm->chat($systemPrompt, $history);

        // Persist assistant message
        Message::create([
            'session_id' => $sessionId,
            'role'       => 'assistant',
            'content'    => $aiResponse,
        ]);

        // Extract and store memory asynchronously (in practice, queue this)
        $memorySummary = $this->summarizer->extract($validated['message'], $aiResponse);
        $memoryId      = null;

        if ($memorySummary) {
            $memoryId = $this->icp->storeMemory(
                userId: $userId,
                sessionId: $sessionId,
                content: $memorySummary,
                metadata: json_encode(['source' => 'chat', 'provider' => $this->llm->provider()])
            );
        }

        return response()->json([
            'message'    => $aiResponse,
            'memory_id'  => $memoryId,
            'memory'     => $memorySummary,
            'provider'   => $this->llm->provider(),
        ]);
    }

    /**
     * Reset the current chat session.
     */
    public function reset(Request $request)
    {
        $sessionId = session()->get('chat_session_id');

        if ($sessionId) {
            Message::where('session_id', $sessionId)->delete();
        }

        session()->forget(['chat_session_id', 'chat_user_id']);

        return redirect()->route('chat');
    }
}
