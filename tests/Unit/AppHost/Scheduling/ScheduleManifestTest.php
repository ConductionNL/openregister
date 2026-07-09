<?php
/**
 * AppHost scheduling — manifest parsing tests.
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

use OCA\OpenRegister\AppHost\Scheduling\CronScheduleEvaluator;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifest;
use PHPUnit\Framework\TestCase;

/**
 * Covers `schedules[]` parsing, cron-rejection, and diagnostics collection.
 */
class ScheduleManifestTest extends TestCase
{
    private CronScheduleEvaluator $cron;

    protected function setUp(): void
    {
        $this->cron = new CronScheduleEvaluator();
    }

    public function testAbsentBlockYieldsNoSchedules(): void
    {
        $m = ScheduleManifest::fromManifest('app', ['name' => 'App'], $this->cron);
        $this->assertSame([], $m->schedules);
        $this->assertSame([], $m->diagnostics);
    }

    public function testValidMixParses(): void
    {
        $m = ScheduleManifest::fromManifest('app', [
            'schedules' => [
                ['id' => 'a', 'interval' => 60, 'action' => 'openconnector:synchronization'],
                ['id' => 'b', 'cron' => '0 6 * * 1-5', 'action' => 'openconnector:synchronization'],
            ],
        ], $this->cron);

        $this->assertCount(2, $m->schedules);
        $this->assertSame([], $m->diagnostics);
    }

    public function testUnparseableCronRejectedWithDiagnostic(): void
    {
        $m = ScheduleManifest::fromManifest('app', [
            'schedules' => [
                ['id' => 'bad', 'cron' => 'not-a-cron', 'action' => 'openconnector:synchronization'],
                ['id' => 'ok', 'interval' => 60, 'action' => 'openconnector:synchronization'],
            ],
        ], $this->cron);

        $this->assertCount(1, $m->schedules);
        $this->assertSame('ok', $m->schedules[0]->id);
        $this->assertNotEmpty($m->diagnostics);
    }

    public function testInvalidEntryCollectedNotThrown(): void
    {
        $m = ScheduleManifest::fromManifest('app', [
            'schedules' => [
                ['id' => 'both', 'interval' => 60, 'cron' => '* * * * *', 'action' => 'x'],
                'not-an-object',
            ],
        ], $this->cron);

        $this->assertSame([], $m->schedules);
        $this->assertCount(2, $m->diagnostics);
    }
}//end class
