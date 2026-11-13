<?php

/**
 * Characterisation test for the metadata-arm probe query of the content search.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Service\Object\ContentSearchHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins which keys the probe query drops, which it keeps, and the offset it resets.
 */
class ContentSearchProbeQueryTest extends TestCase {

	/**
	 * The probe of a query that carries every key the probe drops, plus some it keeps.
	 *
	 * @return void
	 */
	public function testTheProbeDropsPagingFacetsAndScopeAndKeepsTheFilters(): void {
		$query = [
			'_search' => 'q',
			'status' => 'open',
			'_order' => ['title' => 'asc'],
			'_limit' => 5,
			'_offset' => 10,
			'_page' => 3,
			'_facetable' => true,
			'_facets' => ['status'],
			'_aggregations' => ['x'],
			'_extend' => ['@self.owner'],
			'_fields' => ['title'],
			'_content_search' => true,
			'_register' => 1,
			'_registers' => [1, 2],
			'_schema' => 3,
			'_schemas' => [3, 4],
			'register' => 1,
			'schema' => 3,
			'_ids' => ['a'],
			'@self' => ['register' => 1, 'registers' => [1], 'schema' => 3, 'schemas' => [3], 'owner' => 'alice'],
		];

		$this->assertSame(
			[
				'_search' => 'q',
				'status' => 'open',
				'_order' => ['title' => 'asc'],
				'@self' => ['owner' => 'alice'],
				'_offset' => 0,
			],
			$this->probe($query)
		);
	}//end testTheProbeDropsPagingFacetsAndScopeAndKeepsTheFilters()

	/**
	 * A query without `@self`, or with a non-array one, keeps it as it is.
	 *
	 * @return void
	 */
	public function testANonArraySelfIsLeftAlone(): void {
		$this->assertSame(['_search' => 'q', '_offset' => 0], $this->probe(['_search' => 'q']));
		$this->assertSame(['@self' => 'x', '_offset' => 0], $this->probe(['@self' => 'x', '_offset' => 4]));
	}//end testANonArraySelfIsLeftAlone()

	/**
	 * The probe the handler builds.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return array<string, mixed>
	 */
	private function probe(array $query): array {
		$handler = new ContentSearchHandler(
			$this->createMock(ChunkMapper::class),
			$this->createMock(FileMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(LoggerInterface::class)
		);

		return (new ReflectionMethod(ContentSearchHandler::class, 'probeQuery'))->invoke($handler, $query);
	}//end probe()
}//end class
