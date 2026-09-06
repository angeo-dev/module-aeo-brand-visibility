<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Api;

use Angeo\AeoBrandVisibility\Model\Result\ProviderResponse;

/**
 * Contract for an AI provider that can answer a text prompt.
 *
 * 2.0.0 (BC break): query() returns a structured ProviderResponse instead of
 * a bare string, and providers declare their grounding capability. Third-party
 * providers register via di.xml on BrandVisibilityService's $providers array.
 */
interface AiProviderInterface
{
    /**
     * @throws \RuntimeException on API error, auth failure, or timeout
     */
    public function query(string $systemPrompt, string $userPrompt): ProviderResponse;

    public function getProviderId(): string;

    public function getProviderLabel(): string;

    public function isConfigured(): bool;

    /**
     * Can this provider answer with live web access at all?
     */
    public function supportsGrounding(): bool;

    /**
     * Will the NEXT query use live web access?
     * (Perplexity: always true. Groq: always false. Others: admin toggle.)
     */
    public function isGrounded(): bool;
}
