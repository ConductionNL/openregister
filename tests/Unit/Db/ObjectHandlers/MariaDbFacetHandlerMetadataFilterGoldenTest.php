<?php

/**
 * Golden record of the WHERE clauses MariaDbFacetHandler::applyMetadataFilters() adds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\ObjectHandlers;

use OCA\OpenRegister\Db\ObjectHandlers\MariaDbFacetHandler;
use OCA\OpenRegister\Tests\Support\SqlComposite;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ObjectHandlers\MariaDbFacetHandler
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */
class MariaDbFacetHandlerMetadataFilterGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/mariadb-metadata-filter-golden.json';

	public static function cases(): array {
		$cases = [
			'scalar equals' => ['owner' => 'alice'],
			'scalar integer' => ['register' => 5],
			'is not null' => ['published' => 'IS NOT NULL'],
			'is null' => ['depublished' => 'IS NULL'],
			'list of ids' => ['register' => [1, 2, 3]],
			'list of strings reads as operators' => ['owner' => ['alice', 'bob']],
			'empty array' => ['owner' => []],
			'comparisons' => ['created' => ['gt' => '2026-01-01', 'lt' => '2026-12-31', 'gte' => 1, 'lte' => 9, 'ne' => 'x', '===' => 'Exact']],
			'likes' => ['name' => ['~' => 'mid', '^' => 'start', '$' => 'end']],
			'exists true' => ['owner' => ['exists' => true]],
			'exists string true' => ['owner' => ['exists' => 'true']],
			'exists false' => ['owner' => ['exists' => false]],
			'exists other' => ['owner' => ['exists' => 1]],
			'empty true' => ['owner' => ['empty' => true]],
			'empty false' => ['owner' => ['empty' => 'false']],
			'null true' => ['owner' => ['null' => 'true']],
			'null false' => ['owner' => ['null' => false]],
			'unknown operator' => ['owner' => ['weird' => 'v']],
			'several fields' => ['owner' => 'a', 'register' => [7], 'created' => ['gte' => 3], 'deleted' => 'IS NULL'],
		];

		$out = [];
		foreach ($cases as $name => $case) {
			$out[$name] = [$case];
		}

		return $out;
	}//end cases()

	/**
	 * A query builder that records each WHERE clause as text.
	 *
	 * @param array $log The clauses, appended to.
	 *
	 * @return IQueryBuilder
	 */
	private function recorder(array &$log): IQueryBuilder {
		$count = 0;
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = IQueryBuilder::PARAM_STR) use (&$count) {
				$count++;
				return ':p' . $count . '=' . json_encode($value) . '/' . (string)$type;
			}
		);
		foreach (['gt', 'lt', 'gte', 'lte', 'neq', 'eq', 'like', 'in'] as $method) {
			$expr->method($method)->willReturnCallback(fn ($x, $y) => $method . '(' . $x . ', ' . $y . ')');
		}

		foreach (['isNull', 'isNotNull'] as $method) {
			$expr->method($method)->willReturnCallback(fn ($x) => $method . '(' . $x . ')');
		}

		foreach (['andX', 'orX'] as $method) {
			$expr->method($method)->willReturnCallback(
				fn (...$parts) => new SqlComposite(strtoupper(substr($method, 0, -1)), array_map('strval', $parts))
			);
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('andWhere')->willReturnCallback(
			function (...$parts) use (&$log, $qb) {
				$log[] = implode(' AND ', array_map('strval', $parts));
				return $qb;
			}
		);

		return $qb;
	}//end recorder()

	#[DataProvider('cases')]
	public function testMetadataFiltersMatchGolden(array $filters): void {
		$log = [];
		$handler = new MariaDbFacetHandler($this->createMock(IDBConnection::class));
		(new \ReflectionMethod($handler, 'applyMetadataFilters'))->invoke($handler, $this->recorder($log), $filters);

		$name = $this->dataName();
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $log;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $log);
	}//end testMetadataFiltersMatchGolden()
}//end class
