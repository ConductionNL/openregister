<?php

/**
 * Golden pins for the related-row renderer and access predicate, written against the code before its > 2 parameter refactor.
 *
 * 🔴 THESE PIN ALLOWED AND REFUSED OUTCOMES BYTE FOR BYTE, for the branches the
 * existing goldens (`RelatedRowQueryGoldenTest`, `RelatedRowAccessGoldenTest`)
 * leave open: which refusal wins when a forward join meets an unknown engine,
 * a column name the identifier check refuses (in a condition and in the join),
 * an `in` on an unknown engine, an organisation scope without a `uuids` key,
 * and a scope whose uuids are not all usable.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Query
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Query;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Query\RelatedRowAccess;
use OCA\OpenRegister\Service\Query\RelatedRowExistsClause;
use OCA\OpenRegister\Service\Query\RelatedRowFilter;
use OCA\OpenRegister\Service\Query\RelatedRowInnerRow;
use OCA\OpenRegister\Service\Query\RelatedRowSource;
use OCA\OpenRegister\Service\Query\RelatedRowSqlTarget;
use OCA\OpenRegister\Service\Query\RelatedRowSubquery;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Golden pins: `RelatedRowExistsClause::render()` and `RelatedRowAccess::predicate()`.
 *
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowExistsClause
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowFilter
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowInnerRow
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowSource
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowSqlTarget
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowSubquery
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowField
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowBoundCondition
 */
class RelatedRowParameterBandGoldenTest extends TestCase {

	/**
	 * The stock row predicate the RBAC mock returns.
	 */
	private const ROW_PREDICATE = 'r._owner = 1';

	/**
	 * Render one clause; the one place the renderer's call shape is written.
	 *
	 * @param RelatedRowFilter    $filter    The filter.
	 * @param RelatedRowSqlTarget $target    The target.
	 * @param string              $predicate The access predicate.
	 *
	 * @return array{sql: string, parameters: array<string, mixed>} The clause.
	 */
	private function renderClause(RelatedRowFilter $filter, RelatedRowSqlTarget $target, string $predicate): array {
		return (new RelatedRowExistsClause())->render(
			filter: $filter,
			subquery: new RelatedRowSubquery(target: $target,
			inner: new RelatedRowInnerRow(alias: 'r1', accessPredicate: $predicate, parameterPrefix: 'q1'))
		);
	}//end renderClause()

	/**
	 * The predicate for one scope; the one place the access call shape is written.
	 *
	 * @param IQueryBuilder $qb    The query.
	 * @param array<mixed>  $scope The scope.
	 *
	 * @return string The predicate.
	 */
	private function predicateFor(IQueryBuilder $qb, array $scope): string {
		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->method('buildRbacPredicateForAlias')->willReturn(self::ROW_PREDICATE);

		return (new RelatedRowAccess(rbac: $rbac, logger: new NullLogger()))->predicate(
			qb: $qb,
			source: new RelatedRowSource(schema: new Schema(), tableName: 'openregister_table_3_4', alias: 'r1', scope: $scope)
		);
	}//end predicateFor()

	/**
	 * Clauses the existing goldens do not pin.
	 *
	 * @return array<string, array{0: RelatedRowFilter, 1: RelatedRowSqlTarget, 2: string, 3: array<string, mixed>}> Filter, target, SQL, parameters.
	 */
	public static function clauses(): array {
		$mixed = [
			['field' => 'a', 'operator' => 'in', 'value' => 'single'],
			['field' => 'b', 'operator' => 'gt', 'value' => 7],
			['field' => 'c', 'operator' => 'eq', 'value' => 9],
			['field' => 'd', 'operator' => 'lte', 'value' => true],
		];

		return [
			'mysql json forward, scalar in, int bound, bool bound' => [
				new RelatedRowFilter('cp', 'ref', $mixed, RelatedRowFilter::DIRECTION_FORWARD),
				new RelatedRowSqlTarget(engine: 'mysql', table: 'tbl', outerAlias: 'o'),
				'EXISTS (SELECT 1 FROM tbl r1 WHERE r1."schema" = :q1_schema AND r1.uuid = JSON_UNQUOTE(JSON_EXTRACT(o.object, \'$.ref\'))'
				. ' AND r1.deleted IS NULL AND (P) AND JSON_UNQUOTE(JSON_EXTRACT(r1.object, \'$.a\')) IN (:q1_c0_0)'
				. ' AND (CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(r1.object, \'$.b\')) REGEXP \'^-?[0-9]+(\\.[0-9]+)?$\' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(r1.object, \'$.b\')) AS DECIMAL(65,30)) > :q1_c1 ELSE FALSE END)'
				. ' AND JSON_UNQUOTE(JSON_EXTRACT(r1.object, \'$.c\')) = :q1_c2'
				. ' AND (CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(r1.object, \'$.d\')) REGEXP \'^-?[0-9]+(\\.[0-9]+)?$\' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(r1.object, \'$.d\')) AS DECIMAL(65,30)) <= :q1_c3 ELSE FALSE END))',
				['q1_schema' => 'cp', 'q1_c0_0' => 'single', 'q1_c1' => 7, 'q1_c2' => 9, 'q1_c3' => true],
			],
			'pgsql columns forward, camelCase key and fields' => [
				new RelatedRowFilter('cp', 'parentCase', $mixed, RelatedRowFilter::DIRECTION_FORWARD),
				new RelatedRowSqlTarget(engine: 'pgsql', table: 'tbl', outerAlias: 'o', storage: 'columns'),
				'EXISTS (SELECT 1 FROM tbl r1 WHERE r1._uuid = o."parent_case" AND r1._deleted IS NULL AND (P)'
				. ' AND r1."a" IN (:q1_c0_0) AND r1."b" > :q1_c1 AND r1."c" = :q1_c2 AND r1."d" <= :q1_c3)',
				['q1_c0_0' => 'single', 'q1_c1' => 7, 'q1_c2' => 9, 'q1_c3' => true],
			],
		];
	}//end clauses()

	/**
	 * Allowed: each clause renders byte-identically with its bindings in the same order.
	 *
	 * @param RelatedRowFilter     $filter     The filter.
	 * @param RelatedRowSqlTarget  $target     The target.
	 * @param string               $sql        The expected SQL.
	 * @param array<string, mixed> $parameters The expected bindings.
	 *
	 * @return void
	 */
	#[DataProvider('clauses')]
	public function testTheClauseIsByteIdentical(RelatedRowFilter $filter, RelatedRowSqlTarget $target, string $sql, array $parameters): void {
		$rendered = $this->renderClause(filter: $filter, target: $target, predicate: 'P');

		$this->assertSame($sql, $rendered['sql']);
		$this->assertSame($parameters, $rendered['parameters']);
	}//end testTheClauseIsByteIdentical()

	/**
	 * Refusals the existing goldens do not pin, and which one wins.
	 *
	 * @return array<string, array{0: RelatedRowFilter, 1: RelatedRowSqlTarget, 2: string}> Filter, target, message.
	 */
	public static function refusals(): array {
		$eq = [['field' => 'x', 'operator' => 'eq', 'value' => '1']];

		return [
			'forward join on unknown engine' => [
				new RelatedRowFilter('cp', 'ref', [], RelatedRowFilter::DIRECTION_FORWARD),
				new RelatedRowSqlTarget(engine: 'oracle', table: 'tbl', outerAlias: 'o'),
				"No related-row SQL for engine 'oracle'.",
			],
			'in on unknown engine' => [
				new RelatedRowFilter('cp', 'ref', [['field' => 'x', 'operator' => 'in', 'value' => ['a']]], RelatedRowFilter::DIRECTION_FORWARD),
				new RelatedRowSqlTarget(engine: 'oracle', table: 'tbl', outerAlias: 'o', storage: 'columns'),
				'',
			],
			'unknown engine wins over unknown operator on json' => [
				new RelatedRowFilter('cp', 'ref', [['field' => 'x', 'operator' => 'like', 'value' => '1']]),
				new RelatedRowSqlTarget(engine: 'oracle', table: 'tbl', outerAlias: 'o'),
				"No related-row SQL for engine 'oracle'.",
			],
			'refused column name in a condition' => [
				new RelatedRowFilter('cp', 'ref', [['field' => '9lives', 'operator' => 'eq', 'value' => '1']]),
				new RelatedRowSqlTarget(engine: 'pgsql', table: 'tbl', outerAlias: 'o', storage: 'columns'),
				"'9lives' is not a column name a related-row filter may reference.",
			],
			'refused column name in the join wins over the condition' => [
				new RelatedRowFilter('cp', '1ref', $eq, RelatedRowFilter::DIRECTION_FORWARD),
				new RelatedRowSqlTarget(engine: 'pgsql', table: 'tbl', outerAlias: 'o', storage: 'columns'),
				"'1ref' is not a column name a related-row filter may reference.",
			],
		];
	}//end refusals()

	/**
	 * Refused: each refusal keeps its exact message; an empty message means the clause renders.
	 *
	 * @param RelatedRowFilter    $filter  The filter.
	 * @param RelatedRowSqlTarget $target  The target.
	 * @param string              $message The refusal, or '' when the clause must render.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testTheClauseRefusesWithTheExactMessage(RelatedRowFilter $filter, RelatedRowSqlTarget $target, string $message): void {
		if ($message === '') {
			$rendered = $this->renderClause(filter: $filter, target: $target, predicate: 'P');
			$this->assertSame('EXISTS (SELECT 1 FROM tbl r1 WHERE r1._uuid = o."ref" AND r1._deleted IS NULL AND (P) AND r1."x" IN (:q1_c0_0))', $rendered['sql']);
			return;
		}

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$this->renderClause(filter: $filter, target: $target, predicate: 'P');
	}//end testTheClauseRefusesWithTheExactMessage()

	/**
	 * Allowed and refused organisation scopes the existing goldens do not pin.
	 *
	 * @return void
	 */
	public function testThePredicateForUnpinnedScopes(): void {
		$cases = [
			'in without uuids key'    => [['mode' => MagicOrganizationHandler::SCOPE_IN], '(' . self::ROW_PREDICATE . ') AND 1 = 0', []],
			'empty scope'             => [[], '(' . self::ROW_PREDICATE . ') AND 1 = 0', []],
			'in-or-null, keyed uuids' => [
				['mode' => MagicOrganizationHandler::SCOPE_IN_OR_NULL, 'uuids' => ['k' => 'org-x', 'j' => false, 'i' => 'org-y']],
				'(' . self::ROW_PREDICATE . ') AND (r1._organisation IN (:r1_org0, :r1_org1) OR r1._organisation IS NULL)',
				['r1_org0' => 'org-x', 'r1_org1' => 'org-y'],
			],
			'all ignores uuids'       => [['mode' => MagicOrganizationHandler::SCOPE_ALL], self::ROW_PREDICATE, []],
		];

		foreach ($cases as $label => [$scope, $expected, $expectedParameters]) {
			$parameters = [];
			$qb         = $this->createMock(IQueryBuilder::class);
			$qb->method('setParameter')->willReturnCallback(function (string $name, mixed $value) use (&$parameters, $qb) {
				$parameters[$name] = $value;
				return $qb;
			});

			$this->assertSame($expected, $this->predicateFor(qb: $qb, scope: $scope), $label);
			$this->assertSame($expectedParameters, $parameters, $label);
		}
	}//end testThePredicateForUnpinnedScopes()
}//end class
