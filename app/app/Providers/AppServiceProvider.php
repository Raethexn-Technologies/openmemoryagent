<?php

namespace App\Providers;

use App\Services\IcpMemoryService;
use App\Services\LLM\ClaudeProvider;
use App\Services\LLM\GeminiProvider;
use App\Services\LLM\LlmProviderInterface;
use App\Services\LLM\LlmService;
use App\Services\LLM\OpenAIProvider;
use App\Services\MemorySummarizationService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind the LLM provider based on config — swap via LLM_PROVIDER env var
        $this->app->bind(LlmProviderInterface::class, function () {
            $provider = config('services.llm.provider', 'claude');

            return match ($provider) {
                'gemini' => new GeminiProvider(
                    apiKey: config('services.llm.gemini_api_key'),
                    model: config('services.llm.gemini_model', 'gemini-1.5-flash'),
                ),
                'openai' => new OpenAIProvider(
                    apiKey: config('services.llm.openai_api_key'),
                    model: config('services.llm.openai_model', 'gpt-4o-mini'),
                ),
                default => new ClaudeProvider(
                    apiKey: config('services.llm.claude_api_key'),
                    model: config('services.llm.claude_model', 'claude-sonnet-4-6'),
                ),
            };
        });

        $this->app->singleton(LlmService::class, function ($app) {
            return new LlmService($app->make(LlmProviderInterface::class));
        });

        $this->app->singleton(IcpMemoryService::class);

        $this->app->singleton(MemorySummarizationService::class, function ($app) {
            return new MemorySummarizationService($app->make(LlmService::class));
        });
    }

    public function boot(): void
    {
        //
    }
}
