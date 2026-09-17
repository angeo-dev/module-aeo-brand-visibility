<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Test\Unit\Service\Webhook;

use Angeo\AeoBrandVisibility\Service\Webhook\WebhookUrlValidator;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * Covers the request forgery guard on the alert webhook.
 */
class WebhookUrlValidatorTest extends TestCase
{
    private WebhookUrlValidator $validator;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->validator = new WebhookUrlValidator();
    }

    /**
     * Plain HTTP must be rejected.
     *
     * @return void
     */
    public function testHttpIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->validator->validate('http://hooks.example.com/endpoint');
    }

    /**
     * A malformed URL must be rejected.
     *
     * @return void
     */
    public function testMalformedUrlIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->validator->validate('not-a-url');
    }

    /**
     * Loopback must be rejected by default.
     *
     * @return void
     */
    public function testLoopbackIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->validator->validate('https://127.0.0.1/hook');
    }

    /**
     * The cloud metadata address must be rejected by default.
     *
     * @return void
     */
    public function testLinkLocalMetadataAddressIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->validator->validate('https://169.254.169.254/latest/meta-data/');
    }

    /**
     * A private address must be allowed once an operator opts in.
     *
     * @return void
     */
    public function testPrivateAddressPassesWhenExplicitlyAllowed(): void
    {
        $this->validator->validate('https://10.0.0.5/hook', true);
        $this->addToAssertionCount(1);
    }

    /**
     * A public address must pass.
     *
     * @return void
     */
    public function testPublicAddressPasses(): void
    {
        $this->validator->validate('https://93.184.216.34/hook');
        $this->addToAssertionCount(1);
    }
}
