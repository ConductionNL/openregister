<?php
/**
 * AppHost scheduling — action allow-list tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleActionAllowList;
use PHPUnit\Framework\TestCase;

/**
 * Covers the closed action → vetted jobClass map.
 */
class ScheduleActionAllowListTest extends TestCase
{
    private ScheduleActionAllowList $allowList;

    protected function setUp(): void
    {
        $this->allowList = new ScheduleActionAllowList();
    }

    public function testVettedActionResolvesToServerClass(): void
    {
        $this->assertTrue($this->allowList->isAllowed('openconnector:synchronization'));
        $this->assertSame(
            'OCA\\OpenConnector\\Action\\SynchronizationAction',
            $this->allowList->resolve('openconnector:synchronization')
        );
    }

    public function testNonAllowListedActionIsRejected(): void
    {
        $this->assertFalse($this->allowList->isAllowed('openconnector:unknown'));
        $this->assertNull($this->allowList->resolve('openconnector:unknown'));
    }

    public function testRawFqcnIsNeverResolved(): void
    {
        // A manifest-supplied FQCN must never be usable as a jobClass.
        $this->assertNull($this->allowList->resolve('OCA\\Evil\\Backdoor'));
        $this->assertFalse($this->allowList->isAllowed('OCA\\OpenConnector\\Action\\SynchronizationAction'));
    }
}//end class
