<?php

/**
 * TargetedMigrationStepTest: outside a step-by-step upgrade a step works on the schema it is given.
 *
 * The targeted path itself needs a database: tests/Db/TargetedMigrationTest.php.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use Closure;
use OCA\OpenRegister\Migration\TargetedMigrationStep;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Migration\TargetedMigrationStep
 */
class TargetedMigrationStepTest extends TestCase {

	/**
	 * A step that records the closure each hook was handed and returns what the change hook got from it.
	 *
	 * @return TargetedMigrationStep
	 */
	private function step(): TargetedMigrationStep {
		return new class extends TargetedMigrationStep {
			/** @var array<string, Closure> */
			public array $handed = [];

			protected function beforeTargetedSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
				$this->handed['before'] = $schemaClosure;
			}

			protected function changeTargetedSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
				$this->handed['change'] = $schemaClosure;
				return $schemaClosure();
			}

			protected function afterTargetedSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
				$this->handed['after'] = $schemaClosure;
			}
		};
	}//end step()

	public function testAnInstallOrSchemaCheckGetsTheSharedSchemaBack(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$shared = static fn (): ISchemaWrapper => $schema;
		$step = $this->step();

		// No preSchemaChange() first: that is how an install and a schema check call a step.
		$returned = $step->changeSchema($this->createMock(IOutput::class), $shared, []);

		$this->assertSame($schema, $returned);
		$this->assertSame($shared, $step->handed['change']);
	}//end testAnInstallOrSchemaCheckGetsTheSharedSchemaBack()

	public function testAClosureThatIsNotNextcloudsIsHandedThroughOnEveryHook(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		// Bound to this test, as a migration test's closure is, not to Nextcloud's MigrationService.
		$own = fn (): ISchemaWrapper => $schema;
		$output = $this->createMock(IOutput::class);
		$step = $this->step();

		$step->preSchemaChange($output, $own, []);
		$returned = $step->changeSchema($output, $own, []);
		$step->postSchemaChange($output, $own, []);

		$this->assertSame($schema, $returned);
		$this->assertSame(['before' => $own, 'change' => $own, 'after' => $own], $step->handed);
	}//end testAClosureThatIsNotNextcloudsIsHandedThroughOnEveryHook()

	public function testAStepWithoutHooksChangesNothing(): void {
		$step = new class extends TargetedMigrationStep {
		};
		$never = function (): ISchemaWrapper {
			$this->fail('A step without a schema change must not ask for the schema.');
		};
		$output = $this->createMock(IOutput::class);

		$step->preSchemaChange($output, $never, []);
		$this->assertNull($step->changeSchema($output, $never, []));
		$step->postSchemaChange($output, $never, []);
	}//end testAStepWithoutHooksChangesNothing()
}//end class
