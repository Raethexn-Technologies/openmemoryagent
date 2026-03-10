<?php

namespace App\Services\LLM;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiProvider implements LlmProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gemini-2.0-flash',
        private readonly int $maxTokens = 1024,
    ) {}

    public function chat(string $systemPrompt, array $messages): string
    {
        // Convert to Gemini's format
        $contents = [];

        foreach ($messages as $msg) {
            $contents[] = [
                'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $msg['content']]],
            ];
        }

        $response = Http::post(
            "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}",
            [
                'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents'           => $contents,
                'generationConfig'   => ['maxOutputTokens' => $this->maxTokens],
            ]
        );

        if ($response->failed()) {
            throw new RuntimeException('Gemini API error: ' . $response->body());
        }

        return $response->json('candidates.0.content.parts.0.text', '');
    }

    public function name(): string
    {
        return 'gemini';
    }
}
