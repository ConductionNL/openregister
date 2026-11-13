<?php

/**
 * A bulk upsert command hands each chunk run its table, number and whether
 * the pre-update rows are read.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\BulkUpsertCommand;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Pins chunkRun() of BulkUpsertCommand.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\BulkUpsertCommand
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUpsertChunkRun
 * @uses   \OCA\OpenRegister\Db\RegisterSchema
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\MagicMappingOptions
 */
class BulkUpsertCommandTest extends TestCase {

	/**
	 * A command for the given flag.
	 *
	 * @param bool|null $needsPreUpdateState The flag, or null for the default.
	 *
	 * @return BulkUpsertCommand
	 */
	private function command(?bool $needsPreUpdateState): BulkUpsertCommand {
		$table = new RegisterSchema($this->createMock(Register::class), $this->createMock(Schema::class));
		if ($needsPreUpdateState === null) {
			return new BulkUpsertCommand([['id' => 'a']], $table, 'openregister_table_1_2');
		}

		return new BulkUpsertCommand([['id' => 'a']], $table, 'openregister_table_1_2', $needsPreUpdateState);
	}//end command()

	/**
	 * A chunk run carries the table name and the chunk number, and reads pre-update rows by default.
	 *
	 * @return void
	 */
	public function testAChunkRunCarriesTheTableAndNumber(): void {
		$run = $this->command(null)->chunkRun(3);

		$this->assertSame('openregister_table_1_2', $run->tableName);
		$this->assertSame(3, $run->chunkNumber);
		$this->assertTrue($run->needsPreUpdateState);
	}//end testAChunkRunCarriesTheTableAndNumber()

	/**
	 * A command that needs no pre-update rows narrows every chunk run.
	 *
	 * @return void
	 */
	public function testAChunkRunInheritsANarrowedExistenceRead(): void {
		$command = $this->command(false);

		$this->assertFalse($command->chunkRun(1)->needsPreUpdateState);
		$this->assertFalse($command->chunkRun(2)->needsPreUpdateState);
		$this->assertNotSame($command->chunkRun(1), $command->chunkRun(1));
	}//end testAChunkRunInheritsANarrowedExistenceRead()
}//end class
