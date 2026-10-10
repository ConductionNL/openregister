<?php

/**
 * Dropping the separate "recently opened" table.
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

use OCA\OpenRegister\Migration\Version1Date20261009100100;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \OCA\OpenRegister\Migration\Version1Date20261009100100
 */
class Version1Date20261009100100Test extends TestCase {

	/**
	 * The view table is dropped, and only that table.
	 *
	 * @return void
	 */
	public function testItDropsTheViewTable(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_object_views')->willReturn(true);
		$schema->expects($this->once())->method('dropTable')->with('openregister_object_views');

		$this->assertSame($schema, (new Version1Date20261009100100())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}//end testItDropsTheViewTable()

	/**
	 * Without the table there is nothing to drop.
	 *
	 * @return void
	 */
	public function testAMissingTableIsSkipped(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->expects($this->never())->method('dropTable');

		$this->assertSame($schema, (new Version1Date20261009100100())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}//end testAMissingTableIsSkipped()
}//end class
