<?php

namespace App\Services\LLM;

interface LlmProviderInterface
{
    /**
     * Generate a chat response given a system prompt and messages array.
     *
     * @param  string  $systemPrompt
     * @param  array   $messages  [['role' => 'user'|'assistant', 'content' => string], ...]
     * @return string
     */
    public function chat(string $systemPrompt, array $messages): string;

    /**
     * Return a short identifier for this provider.
     */
    public function name(): string;
}
