<?php

/**
 * ReportUnpublishedSchedules names every schedule of every app nobody published.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleCadence;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleCall;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleDescriptor;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStoreInterface;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifest;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifestLoaderInterface;
use OCA\OpenRegister\Repair\ReportUnpublishedSchedules;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Several apps at once: only the unpublished ones are reported, once per schedule.
 */
class ReportUnpublishedSchedulesReportTest extends TestCase {

	private function schedule(string $id): ScheduleDescriptor {
		return new ScheduleDescriptor($id, new ScheduleCadence(3600, null), new ScheduleCall('sync'));
	}

	public function testOnlyUnpublishedAppsAreReportedOncePerSchedule(): void {
		$manifests = $this->createMock(ScheduleManifestLoaderInterface::class);
		$manifests->method('loadAllOnDisk')->willReturn(
			[
				new ScheduleManifest('crm', [$this->schedule('nightly'), $this->schedule('hourly')]),
				new ScheduleManifest('published-app', [$this->schedule('weekly')]),
				new ScheduleManifest('empty-app', []),
			]
		);
		$store = $this->createMock(ScheduleFlowStoreInterface::class);
		$store->method('hasFlowsNamedFor')->willReturnCallback(static fn (string $slug): bool => $slug === 'published-app');

		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message) use (&$logged): void {
			$logged[] = $message;
		});
		$printed = [];
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(function (string $message) use (&$printed): void {
			$printed[] = $message;
		});

		(new ReportUnpublishedSchedules($manifests, $store, $logger))->run($output);

		$expected = [
			'Schedule "nightly" of app "crm" refused: nobody published it, so it has no identity to run as.',
			'Schedule "hourly" of app "crm" refused: nobody published it, so it has no identity to run as.',
		];
		$this->assertSame($expected, $printed);
		$this->assertSame(array_map(static fn (string $m): string => '[AppHost\\Scheduling] ' . $m, $expected), $logged);
	}

	public function testTheStepNameIsWhatOccPrints(): void {
		$step = new ReportUnpublishedSchedules(
			$this->createMock(ScheduleManifestLoaderInterface::class),
			$this->createMock(ScheduleFlowStoreInterface::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame('Report schedules in app manifests that nobody published', $step->getName());
	}
}
