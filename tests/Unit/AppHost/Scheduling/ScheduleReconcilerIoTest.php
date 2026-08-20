<?php

/**
 * AppHost scheduling — regression tests for the ObjectService I/O seams.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use JsonSerializable;
use OCA\OpenRegister\AppHost\Scheduling\CronScheduleEvaluator;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleActionAllowList;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifestLoader;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleReconciler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Exposes the protected loadVirtualApplications() seam for direct testing.
 */
class IoProbeReconciler extends ScheduleReconciler {
	/**
	 * Public passthrough to the protected seam under test.
	 *
	 * @return array<int, array<string, mixed>> The normalised application rows.
	 */
	public function callLoadVirtualApplications(): array {
		return $this->loadVirtualApplications();
	}//end callLoadVirtualApplications()
}//end class

/**
 * Regression tests for the real ObjectService I/O seams (three live-found bugs).
 */
class ScheduleReconcilerIoTest extends TestCase {
	/**
	 * Build an IoProbeReconciler around a given ObjectService mock.
	 *
	 * @param ObjectService $objectService The mocked OR facade.
	 *
	 * @return IoProbeReconciler
	 */
	private function makeReconciler(ObjectService $objectService): IoProbeReconciler {
		$loader = $this->createMock(originalClassName: ScheduleManifestLoader::class);
		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		return new IoProbeReconciler(
			$objectService,
			$loader,
			new CronScheduleEvaluator(),
			new ScheduleActionAllowList(),
			$userManager,
			$logger
		);
	}//end makeReconciler()

	/**
	 * findAll rows that are JsonSerializable entities are normalised to arrays.
	 *
	 * @return void
	 */
	public function testEntityRowsAreNormalisedToArrays(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * Serialise a fixture application row.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return [
					'slug' => 'demo',
					'@self' => ['uuid' => '00000000-0000-0000-0000-000000000000', 'owner' => 'admin'],
				];
			}//end jsonSerialize()
		};

		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('findAll')->willReturn([$entity]);

		$rows = $this->makeReconciler(objectService: $objectService)->callLoadVirtualApplications();

		$this->assertCount(1, $rows);
		$this->assertIsArray($rows[0]);
		$this->assertSame('demo', $rows[0]['slug']);
		$this->assertSame('admin', $rows[0]['@self']['owner']);
	}//end testEntityRowsAreNormalisedToArrays()

	/**
	 * The sweep passes an explicit limit and only _rbac:false (never both-false).
	 *
	 * @return void
	 */
	public function testSweepPassesLimitAndRbacFalseOnly(): void {
		$captured = [];

		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use (&$captured): array {
				$captured = [
					'config' => $config,
					'rbac' => $_rbac,
					'multitenancy' => $_multitenancy,
				];
				return [];
			}
		);

		$this->makeReconciler(objectService: $objectService)->callLoadVirtualApplications();

		$this->assertArrayHasKey('limit', $captured['config']);
		$this->assertGreaterThan(25, $captured['config']['limit']);
		$this->assertFalse($captured['rbac']);
		$this->assertTrue($captured['multitenancy']);
	}//end testSweepPassesLimitAndRbacFalseOnly()
}//end class
