<?php

/**
 * MigrationRun: the report one schema-to-schema migration builds.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\MigrationRun;
use PHPUnit\Framework\TestCase;

/**
 * How counts, failures and warnings end up in the report.
 *
 * @covers \OCA\OpenRegister\Service\Object\MigrationRun
 * @uses \OCA\OpenRegister\Service\Object\MigrationSide
 */
class MigrationRunTest extends TestCase {

	/**
	 * A run with nothing migrated is not a success and carries no failure warning.
	 *
	 * @return void
	 */
	public function testAnEmptyRunIsNotASuccess(): void {
		$run = new MigrationRun([1, 'a'], [2, 'b']);

		$this->assertSame(1, $run->source->register);
		$this->assertSame('b', $run->target->schema);
		$this->assertFalse($run->source->isLoaded());
		$this->assertSame(
			[
				'success' => false,
				'statistics' => ['objectsMigrated' => 0, 'objectsFailed' => 0, 'propertiesMapped' => 0, 'propertiesDiscarded' => 0],
				'details' => [],
				'warnings' => [],
				'errors' => [],
			],
			$run->report()
		);
	}//end testAnEmptyRunIsNotASuccess()

	/**
	 * Mapped and discarded properties add up; one migrated object makes the run a success; a failure adds an error and a closing warning.
	 *
	 * @return void
	 */
	public function testCountsFailuresAndWarningsAreReported(): void {
		$run = new MigrationRun([1, 'a'], [2, 'b']);
		$run->countMapping(['x' => 1, 'y' => 2, 'z' => 3], ['x' => 1]);
		$run->countMapping(['q' => 1], ['q' => 1]);
		$run->migrated();
		$run->warn('slug changed');
		$run->failed(objectId: 'o-2', reason: 'invalid');
		$run->notFound(notFoundId: 'o-3');

		$report = $run->report();

		$this->assertTrue($report['success']);
		$this->assertSame(['objectsMigrated' => 1, 'objectsFailed' => 2, 'propertiesMapped' => 2, 'propertiesDiscarded' => 2], $report['statistics']);
		$this->assertSame(['Failed to migrate object o-2: invalid', 'Failed to migrate object o-3: Object not found'], $report['errors']);
		$this->assertSame(['slug changed', 'Some objects failed to migrate. Check details for specific errors.'], $report['warnings']);
		$this->assertSame([['objectId' => 'o-3', 'objectTitle' => null, 'success' => false, 'error' => 'Object with ID o-3 not found']], $report['details']);
		$this->assertSame($report['warnings'], $run->report()['warnings'], 'reading the report twice does not stack the warning');
	}//end testCountsFailuresAndWarningsAreReported()
}//end class
