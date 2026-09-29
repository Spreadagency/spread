<?php
namespace Spread\Contracts;

/**
 * Contract موحد لأي AI provider — كل adapter لازم ينفّذ الميثودز دي
 */
interface AIProviderContract
{
    /**
     * Chat completion (يدعم vision لو الـ messages فيها image parts)
     *
     * @return array {ok, content, raw, tokens_in, tokens_out, cost_usd, duration_ms, error}
     */
    public function chat(string $model, array $messages, array $options = []): array;

    /**
     * توليد صورة
     *
     * @return array {ok, images: array<string url|b64>, raw, cost_usd, error}
     */
    public function image(string $model, string $prompt, array $options = []): array;

    /** Health check سريع */
    public function ping(): bool;

    /** اسم الموفر */
    public function name(): string;
}
