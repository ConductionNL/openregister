<?php

/**
 * The foreign identifier table: unique per issuer and value, indexed by object.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261114100000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version1Date20261114100000::class)]
class Version1Date20261114100000Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheTableIsCreatedUniqueOnIssuerAndValue(): void {
		$columns = [];
		$column  = $this->createColumnMock();
		$table   = $this->createTableMock();
		$table->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options) use (&$columns, $column) {
				$columns[$name] = [$type, ($options['length'] ?? null), $options['notnull']];
				return $column;
			}
		);
		$table->expects($this->once())->method('setPrimaryKey')->with(['id']);
		$table->expects($this->once())->method('addUniqueIndex')->with(['issuer', 'value'], 'idx_or_fid_issuer_value');
		$table->expects($this->once())->method('addIndex')->with(['object_uuid'], 'idx_or_fid_object');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_foreign_ids')->willReturn(false);
		$schema->expects($this->once())->method('createTable')->with('openregister_foreign_ids')->willReturn($table);

		$this->assertSame($schema, (new Version1Date20261114100000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
		$this->assertSame(['object_uuid', 'issuer', 'value'], array_values(array_intersect(array_keys($columns), ['object_uuid', 'issuer', 'value'])));
		$this->assertSame(128, $columns['issuer'][1]);
		$this->assertSame(191, $columns['value'][1]);
		$this->assertTrue($columns['value'][2]);
	}

	public function testAPresentTableIsLeftAlone(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_foreign_ids')->willReturn(true);
		$schema->expects($this->never())->method('createTable');

		$this->assertSame($schema, (new Version1Date20261114100000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}
}
