<?php
declare(strict_types=1);

/**
 * Picks the image AI provider chosen in the admin (الربط والـ API):
 * Google Gemini, OpenAI, or OpenRouter. Each has its own API key and models;
 * the prompts, timeout and retry settings are shared.
 */
final class AiService
{
    public const PROVIDERS = [
        'gemini' => 'Google Gemini',
        'openai' => 'OpenAI',
        'openrouter' => 'OpenRouter',
    ];

    public static function provider(): string
    {
        $p = (string) Settings::get('ai_provider', 'gemini');
        return array_key_exists($p, self::PROVIDERS) ? $p : 'gemini';
    }

    public static function label(?string $provider = null): string
    {
        return self::PROVIDERS[$provider ?? self::provider()];
    }

    public static function keySet(?string $provider = null): bool
    {
        return Settings::get(($provider ?? self::provider()) . '_api_key', '') !== '';
    }

    /** Image model of the provider, for display. */
    public static function model(?string $provider = null): string
    {
        return (string) Settings::get(($provider ?? self::provider()) . '_model', '');
    }

    public static function fromSettings(?string $provider = null): AiProvider
    {
        $provider ??= self::provider();
        return $provider === 'gemini' ? GeminiService::fromSettings() : OpenAiService::fromSettings($provider);
    }
}
