<?php
namespace Spread;

use Spread\Adapters\OpenRouterAdapter;
use Spread\Adapters\OpenAIAdapter;
use Spread\Adapters\GeminiAdapter;
use Spread\Contracts\AIProviderContract;

class ProviderFactory
{
    private static array $cache = [];

    /**
     * يبني adapter بالاسم — مع cache خلال نفس الـ request
     */
    public static function make(string $providerName): AIProviderContract
    {
        if (isset(self::$cache[$providerName])) {
            return self::$cache[$providerName];
        }

        $row = db_one(
            'SELECT * FROM ai_providers WHERE provider_name = ? AND status = "active" LIMIT 1',
            [$providerName]
        );

        if (!$row) {
            throw new \RuntimeException("Provider '{$providerName}' not found or disabled");
        }

        $apiKey = '';
        if (!empty($row['api_key_encrypted'])) {
            $apiKey = \Crypto::decrypt($row['api_key_encrypted']);
        } elseif (defined('AI_API_KEY') && AI_API_KEY && defined('AI_PROVIDER') && AI_PROVIDER === $providerName) {
            // Migration fallback: المفتاح القديم من config.php لحد ما يتسجل من الأدمن
            $apiKey = AI_API_KEY;
        }

        if ($apiKey === '') {
            throw new \RuntimeException("No API key configured for '{$providerName}' — أضف المفتاح من إدارة الـ AI");
        }

        $adapter = match ($providerName) {
            'openrouter' => new OpenRouterAdapter($apiKey, $row['api_base_url']),
            'openai'     => new OpenAIAdapter($apiKey, $row['api_base_url']),
            'gemini'     => new GeminiAdapter($apiKey, $row['api_base_url']),
            default      => throw new \RuntimeException("Unknown provider: {$providerName}"),
        };

        return self::$cache[$providerName] = $adapter;
    }

    /** كل الموفرين النشطين مرتبين */
    public static function listActive(): array
    {
        $rows = db_all('SELECT provider_name FROM ai_providers WHERE status = "active" ORDER BY is_default DESC, priority ASC');
        return array_column($rows, 'provider_name');
    }
}
