<?php

/**
 * The read-history index on the audit trail.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/read-history-on-audit-trail/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261009100000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \OCA\OpenRegister\Migration\Version1Date20261009100000
 */
class Version1Date20261009100000Test extends TestCase {
	use SchemaTableMockTrait;

	/**
	 * It adds (user, action, object_uuid, created) under a short name.
	 *
	 * @return void
	 */
	public function testItAddsTheReadHistoryIndex(): void {
		$added = [];

		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(true);
		$table->method('hasIndex')->willReturn(false);
		$table->method('addIndex')->willReturnCallback(
			function (array $columns, string $name) use (&$added, $table) {
				$added[$name] = $columns;
				return $table;
			}
		);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_audit_trails')->willReturn(true);
		$schema->method('getTable')->with('openregister_audit_trails')->willReturn($table);

		$result = (new Version1Date20261009100000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertSame($schema, $result);
		$this->assertSame(['or_audit_user_read_hist' => ['user', 'action', 'object_uuid', 'created']], $added);
		// Nextcloud refuses index names over 30 characters on Oracle.
		$this->assertLessThanOrEqual(30, strlen((string)array_key_first($added)));
	}//end testItAddsTheReadHistoryIndex()

	/**
	 * A second run changes nothing.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(true);
		$table->method('hasIndex')->willReturn(true);
		$table->expects($this->never())->method('addIndex');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($table);

		$this->assertSame($schema, (new Version1Date20261009100000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}//end testASecondRunChangesNothing()
}//end class
