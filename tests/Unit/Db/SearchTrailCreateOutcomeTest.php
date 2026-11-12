<?php

/**
 * What SearchTrailMapper::createSearchTrail() stores.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\SearchTrail;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Db\SearchTrailOutcome;
use OCP\AppFramework\Db\Entity;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins the outcome fields, the query parameters and the stamps a created
 * trail carries, without a database: insert() hands the entity back.
 */
final class SearchTrailCreateOutcomeTest extends TestCase {

	/**
	 * A mapper whose insert() returns the entity it is given.
	 *
	 * @return SearchTrailMapper The mapper.
	 */
	private function mapper(): SearchTrailMapper {
		return new class($this->createMock(IDBConnection::class), $this->createMock(IRequest::class), $this->createMock(IUserSession::class), new NullLogger()) extends SearchTrailMapper {
			public function insert(Entity $entity): Entity {
				return $entity;
			}
		};
	}//end mapper()

	/**
	 * The outcome, the query parameters and the stamps land on the trail.
	 *
	 * @return void
	 */
	public function testACreatedTrailCarriesTheOutcomeAndTheQuery(): void {
		$trail = $this->create(query: ['_search' => 'tax', '_limit' => 5, '_page' => 2], counts: [4, 40], responseTime: 13, type: 'async');

		$this->assertSame(4, $trail->getResultCount());
		$this->assertSame(40, $trail->getTotalResults());
		$this->assertSame(13, $trail->getResponseTime());
		$this->assertSame('async', $trail->getExecutionType());
		$this->assertSame('tax', $trail->getSearchTerm());
		$this->assertSame(5, $trail->getLimit());
		$this->assertSame(2, $trail->getPage());
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$trail->getUuid());
		$this->assertNotNull($trail->getCreated());
		$this->assertGreaterThanOrEqual(14, $trail->getSize());
	}//end testACreatedTrailCarriesTheOutcomeAndTheQuery()

	/**
	 * Create a trail through the mapper.
	 *
	 * @param array              $query        The search query.
	 * @param array{0:int,1:int} $counts       Result count and total results.
	 * @param int                $responseTime The response time in whole milliseconds.
	 * @param string             $type         The execution type.
	 *
	 * @return SearchTrail The created trail.
	 */
	private function create(array $query, array $counts, int $responseTime, string $type): SearchTrail {
		return $this->mapper()->createSearchTrail(
			$query,
			new SearchTrailOutcome(resultCount: $counts[0], totalResults: $counts[1], responseTime: $responseTime, executionType: $type)
		);
	}//end create()
}//end class
