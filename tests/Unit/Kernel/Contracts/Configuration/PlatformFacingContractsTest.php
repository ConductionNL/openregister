<?php

/**
 * The Configuration contracts Platform reads: their constants keep today's values and the classes doing the work implement them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel\Contracts\Configuration;

use OCA\OpenRegister\Controller\OperationsConsistencyController;
use OCA\OpenRegister\Controller\OperationsConsoleController;
use OCA\OpenRegister\Controller\OperationsDependenciesController;
use OCA\OpenRegister\Controller\OperationsMaintenanceController;
use OCA\OpenRegister\Kernel\Contracts\Configuration\ExportRightInterface;
use OCA\OpenRegister\Kernel\Contracts\Configuration\MaintenanceReachableInterface;
use OCA\OpenRegister\Kernel\Contracts\Configuration\SeedDirectoryInterface;
use OCA\OpenRegister\Kernel\Contracts\Configuration\SeedOutcomeInterface;
use OCA\OpenRegister\Service\Configuration\SeedDirectoryImporter;
use OCA\OpenRegister\Service\Configuration\SeedReport;
use OCA\OpenRegister\Service\Export\ExportRightService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Kernel\Contracts\Configuration\ExportRightInterface
 * @covers \OCA\OpenRegister\Kernel\Contracts\Configuration\MaintenanceReachableInterface
 * @covers \OCA\OpenRegister\Kernel\Contracts\Configuration\SeedDirectoryInterface
 * @covers \OCA\OpenRegister\Kernel\Contracts\Configuration\SeedOutcomeInterface
 */
final class PlatformFacingContractsTest extends TestCase {

	/**
	 * The constants are the literals the classes declared before the contracts.
	 *
	 * @return void
	 */
	public function testTheConstantsKeepTodaysValues(): void {
		$this->assertSame(expected: 'openregister.seed_directory', actual: SeedDirectoryInterface::CONFIG_KEY);
		$this->assertSame(expected: 'success', actual: SeedOutcomeInterface::STATUS_SUCCESS);
		$this->assertSame(expected: 'export', actual: ExportRightInterface::ACTION);
		$this->assertSame(expected: 'read', actual: ExportRightInterface::FALLBACK_ACTION);
	}//end testTheConstantsKeepTodaysValues()

	/**
	 * The concrete classes inherit the constants, so their own names still resolve to the same values.
	 *
	 * @return void
	 */
	public function testTheConcreteClassesStillAnswerTheirConstants(): void {
		$this->assertSame(expected: SeedDirectoryInterface::CONFIG_KEY, actual: SeedDirectoryImporter::CONFIG_KEY);
		$this->assertSame(expected: SeedOutcomeInterface::STATUS_SUCCESS, actual: SeedReport::STATUS_SUCCESS);
		$this->assertSame(expected: ExportRightInterface::ACTION, actual: ExportRightService::ACTION);
		$this->assertSame(expected: ExportRightInterface::FALLBACK_ACTION, actual: ExportRightService::FALLBACK_ACTION);
	}//end testTheConcreteClassesStillAnswerTheirConstants()

	/**
	 * Each contract is implemented by the class that does the work.
	 *
	 * @return void
	 */
	public function testTheWorkingClassesImplementTheContracts(): void {
		$this->assertTrue(condition: is_subclass_of(SeedDirectoryImporter::class, SeedDirectoryInterface::class));
		$this->assertTrue(condition: is_subclass_of(SeedReport::class, SeedOutcomeInterface::class));
		$this->assertTrue(condition: is_subclass_of(ExportRightService::class, ExportRightInterface::class));
	}//end testTheWorkingClassesImplementTheContracts()

	/**
	 * Exactly the four operations controllers carry the maintenance-reachable marker.
	 *
	 * @return void
	 */
	public function testExactlyTheOperationsControllersCarryTheMarker(): void {
		$marked = [];
		foreach (glob(__DIR__.'/../../../../../lib/Controller/*Controller.php') as $path) {
			$class = 'OCA\\OpenRegister\\Controller\\'.basename($path, '.php');
			if (is_subclass_of($class, MaintenanceReachableInterface::class) === true) {
				$marked[] = $class;
			}
		}

		sort($marked);
		$this->assertSame(
			expected: [
				OperationsConsistencyController::class,
				OperationsConsoleController::class,
				OperationsDependenciesController::class,
				OperationsMaintenanceController::class,
			],
			actual: $marked
		);
	}//end testExactlyTheOperationsControllersCarryTheMarker()
}//end class
