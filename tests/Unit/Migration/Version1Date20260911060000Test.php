<?php

/**
 * Unit tests for the organisation retained-at column.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\OpenRegister\Migration\Version1Date20260911060000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Locks the column's shape and the step's idempotency.
 */
class Version1Date20260911060000Test extends TestCase {

	/**
	 * The column is added nullable, with no default, when it is absent.
	 *
	 * Nullable with no default is the point: a default would stamp every
	 * existing organisation with a retention start that never happened.
	 *
	 * @return void
	 */
	public function testTheColumnIsAddedNullableWithNoDefault(): void {
		$added = [];
		$table = $this->createMock(Table::class);
		$table->method('hasColumn')->willReturn(false);
		$table->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options) use (&$added) {
				$added[] = ['name' => $name, 'type' => $type, 'options' => $options];
				return $this->createMock(\Doctrine\DBAL\Schema\Column::class);
			}
		);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_organisations')->willReturn(true);
		$schema->method('getTable')->with('openregister_organisations')->willReturn($table);

		$step = new Version1Date20260911060000();
		$result = $step->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);

		$this->assertSame($schema, $result);
		$this->assertCount(1, $added);
		$this->assertSame('retained_at', $added[0]['name']);
		$this->assertSame(Types::DATETIME, $added[0]['type']);
		$this->assertFalse($added[0]['options']['notnull']);
		$this->assertArrayNotHasKey('default', $added[0]['options']);

	}//end testTheColumnIsAddedNullableWithNoDefault()

	/**
	 * A column that already exists is left alone, and the run returns null so
	 * Nextcloud records no schema change.
	 *
	 * @return void
	 */
	public function testAnExistingColumnIsLeftAlone(): void {
		$table = $this->createMock(Table::class);
		$table->method('hasColumn')->with('retained_at')->willReturn(true);
		$table->expects($this->never())->method('addColumn');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($table);

		$step = new Version1Date20260911060000();

		$this->assertNull($step->changeSchema($this->createMock(IOutput::class), fn () => $schema, []));

	}//end testAnExistingColumnIsLeftAlone()

	/**
	 * An instance without the organisations table is skipped, not failed.
	 *
	 * @return void
	 */
	public function testAMissingTableIsSkipped(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$step = new Version1Date20260911060000();

		$this->assertNull($step->changeSchema($this->createMock(IOutput::class), fn () => $schema, []));

	}//end testAMissingTableIsSkipped()

}//end class
