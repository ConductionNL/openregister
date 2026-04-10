<?php

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ActionRetryJob;
use PHPUnit\Framework\TestCase;

class ActionRetryJobTest extends TestCase
{
    public function testCalculateDelayExponential(): void
    {
        $this->assertSame(240, ActionRetryJob::calculateDelay('exponential', 2));   // 2^2 * 60 = 240
        $this->assertSame(480, ActionRetryJob::calculateDelay('exponential', 3));   // 2^3 * 60 = 480
        $this->assertSame(960, ActionRetryJob::calculateDelay('exponential', 4));   // 2^4 * 60 = 960
    }

    public function testCalculateDelayLinear(): void
    {
        $this->assertSame(600, ActionRetryJob::calculateDelay('linear', 2));   // 2 * 300 = 600
        $this->assertSame(900, ActionRetryJob::calculateDelay('linear', 3));   // 3 * 300 = 900
    }

    public function testCalculateDelayFixed(): void
    {
        $this->assertSame(300, ActionRetryJob::calculateDelay('fixed', 1));
        $this->assertSame(300, ActionRetryJob::calculateDelay('fixed', 5));
    }

    public function testCalculateDelayUnknownPolicyUsesDefault(): void
    {
        $this->assertSame(300, ActionRetryJob::calculateDelay('unknown', 2));
    }
}
