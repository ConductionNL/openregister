<?php

/**
 * MagicTableIndexCatalogue reads a table's index names from the catalogue of its platform.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/modelling-property-index-switch/tasks.md#1-backend
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexCatalogue;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexCatalogue
 */
class MagicTableIndexCatalogueTest extends TestCase {

	/**
	 * The names read, each once, from pg_indexes on PostgreSQL and
	 * information_schema on MariaDB, for the prefixed table.
	 *
	 * @return void
	 */
	public function testNamesComeFromThePlatformsCatalogueOnce(): void {
		foreach ([true => 'pg_indexes WHERE tablename = :tbl', false => 'information_schema.STATISTICS'] as $isPostgres => $source) {
			$result = $this->createMock(IResult::class);
			$result->method('fetchAll')->willReturn([['indexname' => 't_a_sort_idx'], ['INDEX_NAME' => 't_a_sort_idx'], ['indexname' => 't_uuid_idx'], ['indexname' => '']]);
			$result->expects($this->once())->method('closeCursor');

			$db = $this->createMock(IDBConnection::class);
			$db->expects($this->once())->method('executeQuery')
				->with($this->stringContains($source), ['tbl' => 'oc_t'])
				->willReturn($result);

			$this->assertSame(['t_a_sort_idx', 't_uuid_idx'], (new MagicTableIndexCatalogue($db))->names('oc_t', (bool)$isPostgres));
		}
	}//end testNamesComeFromThePlatformsCatalogueOnce()
}//end class
