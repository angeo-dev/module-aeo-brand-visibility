<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Api;

/**
 * Contract for an AI provider that can answer a text prompt.
 */
interface AiProviderInterface
{
    /**
     * @throws \RuntimeException on API error, auth failure, or timeout
     */
    public function query(string $systemPrompt, string $userPrompt): string;

    public function getProviderId(): string;

    public function getProviderLabel(): string;

    public function isConfigured(): bool;
}
