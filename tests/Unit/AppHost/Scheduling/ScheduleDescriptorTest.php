<?php
/**
 * AppHost scheduling — schedule descriptor parse/validate tests.
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

use OCA\OpenRegister\AppHost\Scheduling\ScheduleDescriptor;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Covers structural validation of a single `schedules[]` entry.
 */
class ScheduleDescriptorTest extends TestCase
{
    private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    public function testIntervalScheduleParses(): void
    {
        $d = ScheduleDescriptor::fromArray([
            'id'        => 'nightly-sync',
            'interval'  => 86400,
            'action'    => 'openconnector:synchronization',
            'arguments' => ['synchronization' => self::NIL_UUID],
        ], 0);

        $this->assertTrue($d->isInterval());
        $this->assertFalse($d->isCron());
        $this->assertSame(86400, $d->intervalSeconds);
        $this->assertTrue($d->enabled);
    }

    public function testCronScheduleParses(): void
    {
        $d = ScheduleDescriptor::fromArray([
            'id'     => 'weekday-report',
            'cron'   => '0 6 * * 1-5',
            'action' => 'openconnector:synchronization',
        ], 0);

        $this->assertTrue($d->isCron());
        $this->assertFalse($d->isInterval());
        $this->assertSame('0 6 * * 1-5', $d->cron);
    }

    public function testBothIntervalAndCronRejected(): void
    {
        $this->expectException(ScheduleValidationException::class);
        ScheduleDescriptor::fromArray([
            'id'       => 'x',
            'interval' => 60,
            'cron'     => '* * * * *',
            'action'   => 'openconnector:synchronization',
        ], 0);
    }

    public function testNeitherIntervalNorCronRejected(): void
    {
        $this->expectException(ScheduleValidationException::class);
        ScheduleDescriptor::fromArray([
            'id'     => 'x',
            'action' => 'openconnector:synchronization',
        ], 0);
    }

    public function testNonPositiveIntervalRejected(): void
    {
        $this->expectException(ScheduleValidationException::class);
        ScheduleDescriptor::fromArray([
            'id'       => 'x',
            'interval' => 0,
            'action'   => 'openconnector:synchronization',
        ], 0);
    }

    public function testMissingIdRejected(): void
    {
        $this->expectException(ScheduleValidationException::class);
        ScheduleDescriptor::fromArray([
            'interval' => 60,
            'action'   => 'openconnector:synchronization',
        ], 0);
    }

    public function testMissingActionRejected(): void
    {
        $this->expectException(ScheduleValidationException::class);
        ScheduleDescriptor::fromArray([
            'id'       => 'x',
            'interval' => 60,
        ], 0);
    }

    public function testEnabledDefaultsTrueAndCanBeFalse(): void
    {
        $d = ScheduleDescriptor::fromArray([
            'id'       => 'x',
            'interval' => 60,
            'action'   => 'openconnector:synchronization',
            'enabled'  => false,
        ], 0);
        $this->assertFalse($d->enabled);
    }

    public function testAuthorSuppliedRunAsIsNotCarried(): void
    {
        // A manifest cannot inject execution identity — runAs must be ignored.
        $d = ScheduleDescriptor::fromArray([
            'id'       => 'x',
            'interval' => 60,
            'action'   => 'openconnector:synchronization',
            'runAs'    => 'admin',
            'owner'    => 'admin',
        ], 0);

        $this->assertObjectNotHasProperty('runAs', $d);
        $this->assertObjectNotHasProperty('owner', $d);
    }
}//end class
