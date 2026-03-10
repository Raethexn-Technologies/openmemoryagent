<?php

namespace App\Services;

use App\Services\LLM\LlmService;

class MemorySummarizationService
{
    private const SUMMARIZE_PROMPT = <<<PROMPT
You are a memory extraction agent. Given a conversation exchange, extract only durable facts about the user.

Rules:
- Extract only facts about the USER (name, profession, location, preferences, goals, etc.)
- Ignore transient details or things the assistant said
- Return a single compact sentence of 20 words or fewer
- If there are no memorable user facts, return exactly: NO_MEMORY
- Do not explain. Just output the memory sentence or NO_MEMORY.
PROMPT;

    public function __construct(
        private readonly LlmService $llm,
    ) {}

    /**
     * Attempt to extract a durable memory from a conversation turn.
     * Returns null if nothing memorable was shared.
     */
    public function extract(string $userMessage, string $assistantResponse): ?string
    {
        $messages = [
            [
                'role'    => 'user',
                'content' => "User said: \"{$userMessage}\"\nAssistant replied: \"{$assistantResponse}\"",
            ],
        ];

        $result = trim($this->llm->chat(self::SUMMARIZE_PROMPT, $messages));

        if ($result === 'NO_MEMORY' || empty($result)) {
            return null;
        }

        return $result;
    }
}
