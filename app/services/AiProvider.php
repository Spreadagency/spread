<?php
declare(strict_types=1);

/** What GenerationService needs from an image AI provider. */
interface AiProvider
{
    /** Throws AiException(kind 'rejected') when the photo is not acceptable. */
    public function safetyCheck(string $jpeg): void;

    /** Returns raw image bytes of the edited photo. */
    public function edit(string $jpeg, string $prompt): string;

    /** Admin "Test connection": ['ok' => bool, 'status' => int, 'ms' => int, 'message' => string] */
    public function test(): array;
}
