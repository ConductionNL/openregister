<?php

/**
 * Characterisation of the shared marker lookup: the columns it selects, the
 * LIKE it builds, how it drains the result, and how it degrades.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Integration\Providers\MarkerLookupTrait;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The marker lookup, pinned.
 */
class MarkerLookupTraitGoldenTest extends TestCase {

	/**
	 * Everything the query builder was asked, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $asked = [];

	/**
	 * A connection whose query answers the given rows, or throws.
	 *
	 * @param array<int, array<string, mixed>> $rows  The rows the result yields.
	 * @param bool                             $fails Whether executing the query throws.
	 *
	 * @return IDBConnection The connection.
	 */
	private function connection(array $rows, bool $fails = false): IDBConnection {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('iLike')->willReturnCallback(
			function (string $column, string $param): string {
				$this->asked[] = ['iLike', $column, $param];

				return 'condition';
			}
		);

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnCallback(
			function (...$columns) use ($qb): IQueryBuilder {
				$this->asked[] = ['select', $columns];

				return $qb;
			}
		);
		$qb->method('from')->willReturnCallback(
			function (string $table) use ($qb): IQueryBuilder {
				$this->asked[] = ['from', $table];

				return $qb;
			}
		);
		$qb->method('where')->willReturnCallback(
			function (...$predicates) use ($qb): IQueryBuilder {
				$this->asked[] = ['where', $predicates];

				return $qb;
			}
		);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value): string {
				$this->asked[] = ['param', $value];

				return ':marker';
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			static function () use ($result, $fails): IResult {
				if ($fails === true) {
					throw new RuntimeException('no such table');
				}

				return $result;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}//end connection()

	/**
	 * A minimal provider using the trait, exposing the lookup.
	 *
	 * @param LoggerInterface $logger The provider's logger.
	 *
	 * @return object The provider.
	 */
	private function provider(LoggerInterface $logger): object {
		return new class($logger) {
			use MarkerLookupTrait;

			public function __construct(private LoggerInterface $logger) {
			}//end __construct()

			public function lookup(IDBConnection $db, array $extra, string $idColumn): array {
				return $this->findByMarker($db, 'deck_cards', 'title', '[or:abc]', $extra, $idColumn);
			}//end lookup()
		};
	}//end provider()

	public function testTheLookupSelectsDistinctColumnsAndDrainsTheResult(): void {
		$rows = [['card_id' => 1, 'title' => 'a [or:abc]'], ['card_id' => 2, 'title' => 'b [or:abc]']];

		$found = $this->provider($this->createMock(LoggerInterface::class))
			->lookup($this->connection($rows), ['title', 'owner', 'card_id'], 'card_id');

		$this->assertSame($rows, $found);
		$this->assertSame(
			[
				['select', ['card_id', 'title', 'owner']],
				['from', 'deck_cards'],
				['param', '%[or:abc]%'],
				['iLike', 'title', ':marker'],
				['where', ['condition']],
			],
			array_map(
				static function (array $entry): array {
					if ($entry[0] === 'select') {
						return ['select', array_values($entry[1])];
					}

					return $entry;
				},
				$this->asked
			)
		);
	}//end testTheLookupSelectsDistinctColumnsAndDrainsTheResult()

	public function testNoRowsIsAnEmptyList(): void {
		$found = $this->provider($this->createMock(LoggerInterface::class))->lookup($this->connection([]), [], 'id');

		$this->assertSame([], $found);
	}//end testNoRowsIsAnEmptyList()

	public function testAFailingQueryDegradesToNothingAndLogsAtDebug(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, get_class($context['exception'])];
			}
		);

		$found = $this->provider($logger)->lookup($this->connection([], true), [], 'id');

		$this->assertSame([], $found);
		$this->assertSame([['[MarkerLookupTrait] deck_cards.title query failed: no such table', RuntimeException::class]], $logged);
	}//end testAFailingQueryDegradesToNothingAndLogsAtDebug()
}//end class
