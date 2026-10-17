<?php
/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Test\Unit\Service\Analysis;

use Angeo\AeoBrandVisibility\Service\Analysis\TermMatcher;
use PHPUnit\Framework\TestCase;

class TermMatcherTest extends TestCase
{
    public function testDoesNotMatchInsideAnotherWord(): void
    {
        $this->assertFalse(TermMatcher::contains('this variable is set', 'aria'));
        $this->assertFalse(TermMatcher::contains('innovation', 'nova'));
        $this->assertFalse(TermMatcher::contains('розетками', 'розетка'));
    }

    public function testMatchesWholeWordsAndPunctuation(): void
    {
        $this->assertTrue(TermMatcher::contains('shop at aria today', 'aria'));
        $this->assertTrue(TermMatcher::contains('visit angeo.dev now', 'angeo'));
        $this->assertTrue(TermMatcher::contains('see angeo.dev/modules', 'angeo.dev'));
        $this->assertTrue(TermMatcher::contains('(angeo)', 'angeo'));
        $this->assertTrue(TermMatcher::contains('магазин розетка тут', 'розетка'));
    }

    public function testPositionIsInCharactersNotBytes(): void
    {
        $this->assertSame(8, TermMatcher::position('ünïcode aria', 'aria'));
        $this->assertNull(TermMatcher::position('variable', 'aria'));
    }

    public function testCountsWholeWordsOnly(): void
    {
        $this->assertSame(2, TermMatcher::count('aria, variable, aria', 'aria'));
        $this->assertSame(0, TermMatcher::count('', 'aria'));
    }
}
