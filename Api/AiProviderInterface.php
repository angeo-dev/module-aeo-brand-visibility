<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Api;

use Angeo\AeoBrandVisibility\Model\Config;

/**
 * Contract for an AI provider that can answer a brand-probing prompt.
 *
 * Implement this interface and add the class to the ProviderPool argument in
 * di.xml to make a new provider available — no core class needs editing.
 *
 * @api
 */
interface AiProviderInterface
{
    /**
     * Send one prompt and return the plain-text answer.
     *
     * @param string $systemPrompt Instruction sent as the system role.
     * @param string $userPrompt The brand-probing question.
     * @param Config $config Store-scoped configuration for this run.
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException On API, auth or transport failure.
     */
    public function query(string $systemPrompt, string $userPrompt, Config $config): string;

    /**
     * Stable machine identifier, e.g. "chatgpt".
     *
     * @return string
     */
    public function getProviderId(): string;

    /**
     * Human-readable label including the active model.
     *
     * @param Config $config Store-scoped configuration.
     * @return string
     */
    public function getProviderLabel(Config $config): string;

    /**
     * Whether the provider is enabled and has the credentials it needs.
     *
     * @param Config $config Store-scoped configuration.
     * @return bool
     */
    public function isConfigured(Config $config): bool;

    /**
     * Whether the provider can answer from live web search rather than model memory.
     *
     * @return bool
     */
    public function supportsGrounding(): bool;

    /**
     * Whether grounding is actually active for the current configuration.
     *
     * @param Config $config Store-scoped configuration.
     * @return bool
     */
    public function isGrounded(Config $config): bool;
}
