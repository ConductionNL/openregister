<?php

/**
 * Repair step reporting unpublished on-disk schedules.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleDescriptor;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStore;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifest;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifestLoader;
use OCA\OpenRegister\Repair\ReportUnpublishedSchedules;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An on-disk schedule with no publisher is logged, not run.
 *
 * @covers \OCA\OpenRegister\Repair\ReportUnpublishedSchedules
 * @uses   \OCA\OpenRegister\AppHost\Scheduling\ScheduleDescriptor
 * @uses   \OCA\OpenRegister\AppHost\Scheduling\ScheduleManifest
 */
class ReportUnpublishedSchedulesTest extends TestCase {
	/**
	 * Run the step against one app with one schedule.
	 *
	 * @param bool $published Whether a compiled flow carries the app's name.
	 * @param int $warnings The warnings expected.
	 *
	 * @return void
	 */
	private function runWith(bool $published, int $warnings): void {
		$schedule = new ScheduleDescriptor('nightly', null, '0 3 * * *', 'a');

		$manifests = $this->createMock(ScheduleManifestLoader::class);
		$manifests->method('loadAllOnDisk')->willReturn([new ScheduleManifest('crm', [$schedule])]);

		$store = $this->createMock(ScheduleFlowStore::class);
		$store->method('hasFlowsNamedFor')->with('crm')->willReturn($published);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly($warnings))->method('warning')
			->with($this->stringContains('"nightly" of app "crm"'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->exactly($warnings))->method('warning');

		(new ReportUnpublishedSchedules($manifests, $store, $logger))->run($output);
	}

	public function testTheSchedulesOfAnAppNobodyPublishedAreLogged(): void {
		$this->runWith(false, 1);
	}

	public function testAnAppWithCompiledFlowsIsNotReported(): void {
		$this->runWith(true, 0);
	}
}
