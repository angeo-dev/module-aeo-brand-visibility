<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Service;

/**
 * Thin wrapper around the process sleep, so rate-limit pacing can be stubbed in tests.
 */
class Sleeper
{
    /**
     * Pause the current process.
     *
     * @param int $milliseconds Pause length; values below one are ignored.
     * @return void
     */
    public function sleep(int $milliseconds): void
    {
        if ($milliseconds < 1) {
            return;
        }

        usleep($milliseconds * 1000);
    }
}
