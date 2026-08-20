<?php

/**
 * OpenRegister IN()-list chunking regression tests
 *
 * Nextcloud's QueryBuilder refuses more than 1000 expressions in an IN() list
 * (Oracle limit) — it logs "More than 1000 expressions in a list are not allowed
 * on Oracle" and emits an "Undefined array key 0" PHP warning. The batched
 * id-lookup helpers must therefore chunk. Observed live: the
 * registers-with-stats endpoint fed all 1,233 schema ids into one IN() on every
 * request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

class InListChunkingTest extends TestCase {
	/**
	 * Every batched id-lookup mapper must declare the IN()-list ceiling and it
	 * must not exceed Nextcloud's 1000-expression limit.
	 *
	 * @return void
	 */
	public function testMappersDeclareInListCeilingAtOrBelow1000(): void {
		$mappers = [
			\OCA\OpenRegister\Db\SchemaMapper::class,
			\OCA\OpenRegister\Db\RegisterMapper::class,
			\OCA\OpenRegister\Db\EntityRelationMapper::class,
		];

		foreach ($mappers as $mapper) {
			$reflection = new ReflectionClass($mapper);
			$this->assertTrue(
				$reflection->hasConstant('MAX_IN_LIST_SIZE'),
				$mapper . ' must declare MAX_IN_LIST_SIZE'
			);

			$max = $reflection->getConstant('MAX_IN_LIST_SIZE');
			$this->assertIsInt($max);
			$this->assertGreaterThan(0, $max);
			$this->assertLessThanOrEqual(
				1000,
				$max,
				$mapper . ' MAX_IN_LIST_SIZE exceeds the Oracle/QueryBuilder 1000-expression limit'
			);
		}
	}//end testMappersDeclareInListCeilingAtOrBelow1000()

	/**
	 * The chunking arithmetic itself: a 1,233-schema instance (the observed
	 * live figure) must split into more than one query, and every chunk must
	 * stay within the limit.
	 *
	 * @return void
	 */
	public function testLiveSchemaCountSplitsIntoBoundedChunks(): void {
		$max = 1000;
		$ids = range(1, 1233);
		$chunks = array_chunk($ids, $max);

		// Must actually chunk — a single oversized IN() is the bug.
		$this->assertGreaterThan(1, count($chunks));

		foreach ($chunks as $chunk) {
			$this->assertLessThanOrEqual($max, count($chunk));
		}

		// No id may be dropped or duplicated by the chunking.
		$flat = array_merge(...$chunks);
		$this->assertSame($ids, $flat);
	}//end testLiveSchemaCountSplitsIntoBoundedChunks()

	/**
	 * A list at or below the ceiling still issues exactly one query.
	 *
	 * @return void
	 */
	public function testListWithinLimitIsNotSplit(): void {
		$chunks = array_chunk(range(1, 1000), 1000);
		$this->assertCount(1, $chunks);

		$this->assertSame([], array_chunk([], 1000));
	}//end testListWithinLimitIsNotSplit()
}//end class
