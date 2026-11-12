<?php

/**
 * The update half of a bulk upsert leaves an existing row's owner and organisation as stored.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicUpsertChunkRun;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicBulkUpsertSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUpsertChunkRun
 */
class MagicBulkHandlerUpsertOwnershipTest extends TestCase {

	/**
	 * The update clauses of an upsert over the usual metadata columns.
	 *
	 * @param bool $postgres Whether the connection is PostgreSQL.
	 *
	 * @return string The clauses, joined.
	 */
	private function updateClauses(bool $postgres): string {
		$handler = (new ReflectionClass(MagicBulkHandler::class))->newInstanceWithoutConstructor();
		$run = new MagicUpsertChunkRun(tableName: 'openregister_table_1_2', chunkNumber: 1);
		$run->fullTableName = 'oc_openregister_table_1_2';
		$run->isPostgres = $postgres;
		$run->columns = ['_uuid', '_owner', '_organisation', '_name', 'title'];

		return implode(', ', (new ReflectionMethod(MagicBulkHandler::class, 'buildUpsertUpdateClauses'))->invoke($handler, $run));
	}

	/**
	 * @return array<string, array{0: bool}> The two dialects.
	 */
	public static function dialects(): array {
		return ['mysql' => [false], 'postgres' => [true]];
	}

	/**
	 * @dataProvider dialects
	 */
	public function testAnExistingRowKeepsItsOwnerAndOrganisation(bool $postgres): void {
		$clauses = $this->updateClauses(postgres: $postgres);

		$this->assertStringNotContainsString('_owner', $clauses);
		$this->assertStringNotContainsString('_organisation', $clauses);
	}

	/**
	 * @dataProvider dialects
	 */
	public function testTheRowsDataIsStillUpdated(bool $postgres): void {
		$clauses = $this->updateClauses(postgres: $postgres);

		$this->assertStringContainsString('_name', $clauses);
		$this->assertStringContainsString('title', $clauses);
	}
}
