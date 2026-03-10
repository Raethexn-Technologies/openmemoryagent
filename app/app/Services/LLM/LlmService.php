<?php

namespace App\Services\LLM;

class LlmService
{
    public function __construct(
        private readonly LlmProviderInterface $provider,
    ) {}

    public function chat(string $systemPrompt, array $messages): string
    {
        return $this->provider->chat($systemPrompt, $messages);
    }

    public function provider(): string
    {
        return $this->provider->name();
    }

    /**
     * Build the agent system prompt with optional injected memory.
     */
    public function buildSystemPrompt(array $memories = []): string
    {
        $base = <<<PROMPT
You are a helpful AI assistant with persistent memory. You remember facts about users across conversations.

When a user shares information about themselves, acknowledge it naturally and let them know you will remember it.
Keep responses conversational, concise, and helpful.
PROMPT;

        if (empty($memories)) {
            return $base;
        }

        $memoryBlock = implode("\n", array_map(
            fn($m) => "- {$m['content']} (stored: {$m['timestamp']})",
            $memories
        ));

        return $base . "\n\n## What you remember about this user:\n{$memoryBlock}\n\nUse this context naturally in your responses.";
    }
}
