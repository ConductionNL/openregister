<?php

/**
 * How AgentMapper and SearchTrailMapper normalise a caller's sort direction.
 *
 * NC 35 types IQueryBuilder::addOrderBy()'s direction as
 * string|SortDirection|null, so a non-string value (an int from a list-shaped
 * sort, a null, an array from a malformed `_order[x][]=` query string) is a
 * TypeError there instead of being passed through to SQL. Both mappers
 * therefore reduce whatever they are given to 'ASC' or 'DESC' before calling
 * addOrderBy(): a case-insensitive 'DESC' sorts descending, everything else
 * ascending — the database default the old pass-through produced for an
 * unrecognised value.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class MapperSortDirectionNormalisationTest extends TestCase {

	/**
	 * Directions a caller can hand either mapper, and what must reach the query.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function directions(): array {
		return [
			'upper-case DESC'          => ['DESC', 'DESC'],
			'lower-case desc'          => ['desc', 'DESC'],
			'mixed-case Desc'          => ['Desc', 'DESC'],
			'upper-case ASC'           => ['ASC', 'ASC'],
			'lower-case asc'           => ['asc', 'ASC'],
			'unknown string'           => ['sideways', 'ASC'],
			'empty string'             => ['', 'ASC'],
			'null'                     => [null, 'ASC'],
			'int (list-shaped sort)'   => [1, 'ASC'],
			'array (malformed query)'  => [['DESC'], 'ASC'],
		];
	}//end directions()

	/**
	 * Invoke a mapper's private normaliseSortDirection().
	 *
	 * @param object $mapper    The mapper under test.
	 * @param mixed  $direction The direction to normalise.
	 *
	 * @return string What the mapper would pass to addOrderBy().
	 */
	private function normalise(object $mapper, mixed $direction): string {
		$method = new ReflectionMethod($mapper, 'normaliseSortDirection');

		return $method->invoke($mapper, $direction);
	}//end normalise()

	#[DataProvider('directions')]
	public function testAgentMapperNormalisesTheDirection(mixed $direction, string $expected): void {
		$mapper = new AgentMapper(
			$this->createMock(IDBConnection::class),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IEventDispatcher::class)
		);

		$this->assertSame($expected, $this->normalise(mapper: $mapper, direction: $direction));
	}//end testAgentMapperNormalisesTheDirection()

	#[DataProvider('directions')]
	public function testSearchTrailMapperNormalisesTheDirection(mixed $direction, string $expected): void {
		$mapper = new SearchTrailMapper(
			$this->createMock(IDBConnection::class),
			$this->createMock(IRequest::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame($expected, $this->normalise(mapper: $mapper, direction: $direction));
	}//end testSearchTrailMapperNormalisesTheDirection()
}//end class
