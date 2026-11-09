<?php

/**
 * Characterisation golden for the related-row query parser, clause and applier.
 *
 * Pins, on the code as it stood before the D14 complexity refactor, the exact
 * filters the parser returns, every refusal message it gives, the exact SQL
 * and bindings the clause renders per engine, storage and direction, and the
 * order in which the applier consults the access rules and touches the query.
 * The refactor must leave every one of these byte-identical.
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
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Query\PropertyReadGuard;
use OCA\OpenRegister\Service\Query\RelatedRowAccess;
use OCA\OpenRegister\Service\Query\RelatedRowExistsClause;
use OCA\OpenRegister\Service\Query\RelatedRowFilter;
use OCA\OpenRegister\Service\Query\RelatedRowFilterParser;
use OCA\OpenRegister\Service\Query\RelatedRowInnerRow;
use OCA\OpenRegister\Service\Query\RelatedRowLookups;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCA\OpenRegister\Service\Query\RelatedRowSqlTarget;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Golden outcomes of the related-row query path.
 *
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowFilterParser
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowExistsClause
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowFilter
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowInnerRow
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowLookups
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowSource
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowSqlTarget
 * @uses \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 */
class RelatedRowQueryGoldenTest extends TestCase {

	/**
	 * Every malformed query and the exact refusal it gets.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}> Key, query, message.
	 */
	public static function refusals(): array {
		return [
			'not an object' => [RelatedRowFilterParser::KEY, ['_related' => 'x'], '_related must be an object of schema blocks, and it is not.'],
			'empty' => [RelatedRowFilterParser::KEY, ['_related' => []], '_related must be an object of schema blocks, and it is not.'],
			'blank schema' => [RelatedRowFilterParser::KEY, ['_related' => [' ' => ['case' => ['a' => 1]]]], '_related[ ] must name a foreign key and at least one condition.'],
			'schema scalar' => [RelatedRowFilterParser::KEY, ['_related' => ['cp' => 'x']], '_related[cp] must name a foreign key and at least one condition.'],
			'schema empty' => [RelatedRowFilterParser::KEY, ['_related' => ['cp' => []]], '_related[cp] must name a foreign key and at least one condition.'],
			'later schema refused after earlier' => [RelatedRowFilterParser::KEY, ['_related' => ['ok' => ['k' => ['a' => 1]], 'cp' => []]], '_related[cp] must name a foreign key and at least one condition.'],
			'blank foreign key' => [RelatedRowFilterParser::KEY, ['_related' => ['cp' => [' ' => ['a' => 1]]]], '_related[cp][ ] must carry at least one condition.'],
			'foreign key scalar' => [RelatedRowFilterParser::KEY, ['_related' => ['cp' => ['case' => 'x']]], '_related[cp][case] must carry at least one condition.'],
			'foreign key empty' => [RelatedRowFilterParser::KEY, ['_related' => ['cp' => ['case' => []]]], '_related[cp][case] must carry at least one condition.'],
			'mixed numbered and bare' => [RelatedRowFilterParser::KEY, ['_related' => ['cp' => ['case' => [0 => ['a' => 1], 'b' => 2]]]], 'A related block mixes numbered rows with bare conditions. Number all of them, or none.'],
			'numbered empty' => [RelatedRowFilterParser::KEY, ['_related' => ['cp' => ['case' => [0 => ['a' => 1], 1 => []]]]], 'The related block at index 1 carries no condition.'],
			'numbered scalar' => [RelatedRowFilterParser::KEY, ['_related' => ['cp' => ['case' => [0 => 'x']]]], 'The related block at index 0 carries no condition.'],
			'blank field' => [RelatedRowFilterParser::KEY, ['_related' => ['cp' => ['case' => [' ' => 1]]]], 'A condition in _related[cp][case] names no field.'],
			'empty value' => [RelatedRowFilterParser::KEY, ['_related' => ['cp' => ['case' => ['v' => []]]]], 'The condition _related[cp][case][v] carries no value.'],
			'unknown operator' => [RelatedRowFilterParser::KEY, ['_related' => ['cp' => ['case' => ['v' => ['gte' => 1, 'like' => 'x']]]]], "The condition _related[cp][case][v] uses operator 'like'. It must be one of: eq, ne, gt, gte, lt, lte, in."],
			'forward key named' => [RelatedRowFilterParser::FORWARD_KEY, ['_referenced' => ['cp' => []]], '_referenced[cp] must name a foreign key and at least one condition.'],
		];
	}//end refusals()

	/**
	 * Each malformed query is refused with exactly the message it had.
	 *
	 * @param string               $key     The parser key.
	 * @param array<string, mixed> $query   The query.
	 * @param string               $message The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testTheParserRefusesWithTheExactMessage(string $key, array $query, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		(new RelatedRowFilterParser(key: $key))->parse(query: $query);
	}//end testTheParserRefusesWithTheExactMessage()

	/**
	 * The parsed filters, flattened for comparison.
	 *
	 * @param array<int, RelatedRowFilter> $filters The filters.
	 *
	 * @return array<int, array<int, mixed>> Schema, foreign key, conditions, direction.
	 */
	private function flatten(array $filters): array {
		return array_map(
			static fn (RelatedRowFilter $filter): array => [$filter->schema, $filter->foreignKey, $filter->conditions, $filter->direction],
			$filters
		);
	}//end flatten()

	/**
	 * Every spelling of a condition, numbered blocks and trimming, in order.
	 *
	 * @return void
	 */
	public function testTheParserReturnsTheExactFilters(): void {
		$query = [
			'_related' => [
				'cp' => [
					'case' => [
						'pd' => 'pd-7',
						'v' => ['gte' => '100', ' lt ' => 5],
						'tags' => ['a', 'b'],
						's' => ['in' => ' x , y'],
						't' => ['in' => ['p']],
					],
					'other' => [0 => ['a' => 1], 1 => ['b' => 2]],
				],
				' sch2 ' => [' fk ' => ['z' => null]],
			],
			'x' => 1,
		];

		$this->assertSame(
			[
				[
					'cp',
					'case',
					[
						['field' => 'pd', 'operator' => 'eq', 'value' => 'pd-7'],
						['field' => 'v', 'operator' => 'gte', 'value' => '100'],
						['field' => 'v', 'operator' => 'lt', 'value' => 5],
						['field' => 'tags', 'operator' => 'in', 'value' => ['a', 'b']],
						['field' => 's', 'operator' => 'in', 'value' => ['x', 'y']],
						['field' => 't', 'operator' => 'in', 'value' => ['p']],
					],
					RelatedRowFilter::DIRECTION_INVERSE,
				],
				['cp', 'other', [['field' => 'a', 'operator' => 'eq', 'value' => 1]], RelatedRowFilter::DIRECTION_INVERSE],
				['cp', 'other', [['field' => 'b', 'operator' => 'eq', 'value' => 2]], RelatedRowFilter::DIRECTION_INVERSE],
				['sch2', 'fk', [['field' => 'z', 'operator' => 'eq', 'value' => null]], RelatedRowFilter::DIRECTION_INVERSE],
			],
			$this->flatten((new RelatedRowFilterParser())->parse(query: $query))
		);
	}//end testTheParserReturnsTheExactFilters()

	/**
	 * Each parser reads only its own key; the forward one runs forward.
	 *
	 * @return void
	 */
	public function testEachParserReadsOnlyItsOwnKey(): void {
		$query = ['_referenced' => ['s' => ['k' => ['a' => 1]]], '_related' => ['ignored' => []]];

		$this->assertSame(
			[['s', 'k', [['field' => 'a', 'operator' => 'eq', 'value' => 1]], RelatedRowFilter::DIRECTION_FORWARD]],
			$this->flatten((new RelatedRowFilterParser(key: RelatedRowFilterParser::FORWARD_KEY))->parse(query: $query))
		);
		$this->assertSame([], (new RelatedRowFilterParser())->parse(query: ['_referenced' => ['s' => []]]));
	}//end testEachParserReadsOnlyItsOwnKey()

	/**
	 * The exact clause per engine, storage and direction.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}> Engine, storage, direction, SQL.
	 */
	public static function clauses(): array {
		return [
			'pgsql json inverse' => ['pgsql', 'json', 'inverse', 'EXISTS (SELECT 1 FROM tbl r0 WHERE r0."schema" = :p0_schema AND r0.object ->> \'caseRef\' = t.uuid AND r0.deleted IS NULL AND (r0.o = 1) AND r0.object ->> \'pd\' = :p0_c0 AND (CASE WHEN r0.object ->> \'v\' ~ \'^-?[0-9]+(\\.[0-9]+)?$\' THEN (r0.object ->> \'v\')::numeric >= :p0_c1 ELSE FALSE END) AND r0.object ->> \'d\' < :p0_c2 AND r0.object ->> \'n\' != :p0_c3 AND r0.object ->> \'o\'\'k\' IN (:p0_c4_0, :p0_c4_1) AND 1 = 0 AND r0.object ->> \'arr\' > :p0_c6 AND (CASE WHEN r0.object ->> \'neg\' ~ \'^-?[0-9]+(\\.[0-9]+)?$\' THEN (r0.object ->> \'neg\')::numeric <= :p0_c7 ELSE FALSE END))'],
			'pgsql json forward' => ['pgsql', 'json', 'forward', 'EXISTS (SELECT 1 FROM tbl r0 WHERE r0."schema" = :p0_schema AND r0.uuid = t.object ->> \'caseRef\' AND r0.deleted IS NULL AND (r0.o = 1) AND r0.object ->> \'pd\' = :p0_c0 AND (CASE WHEN r0.object ->> \'v\' ~ \'^-?[0-9]+(\\.[0-9]+)?$\' THEN (r0.object ->> \'v\')::numeric >= :p0_c1 ELSE FALSE END) AND r0.object ->> \'d\' < :p0_c2 AND r0.object ->> \'n\' != :p0_c3 AND r0.object ->> \'o\'\'k\' IN (:p0_c4_0, :p0_c4_1) AND 1 = 0 AND r0.object ->> \'arr\' > :p0_c6 AND (CASE WHEN r0.object ->> \'neg\' ~ \'^-?[0-9]+(\\.[0-9]+)?$\' THEN (r0.object ->> \'neg\')::numeric <= :p0_c7 ELSE FALSE END))'],
			'pgsql columns inverse' => ['pgsql', 'columns', 'inverse', 'EXISTS (SELECT 1 FROM tbl r0 WHERE r0."case_ref" = t._uuid AND r0._deleted IS NULL AND (r0.o = 1) AND r0."pd" = :p0_c0 AND r0."v" >= :p0_c1 AND r0."d" < :p0_c2 AND r0."n" != :p0_c3 AND 1 = 0 AND r0."arr" > :p0_c5 AND r0."neg" <= :p0_c6)'],
			'pgsql columns forward' => ['pgsql', 'columns', 'forward', 'EXISTS (SELECT 1 FROM tbl r0 WHERE r0._uuid = t."case_ref" AND r0._deleted IS NULL AND (r0.o = 1) AND r0."pd" = :p0_c0 AND r0."v" >= :p0_c1 AND r0."d" < :p0_c2 AND r0."n" != :p0_c3 AND 1 = 0 AND r0."arr" > :p0_c5 AND r0."neg" <= :p0_c6)'],
			'mysql json inverse' => ['mysql', 'json', 'inverse', 'EXISTS (SELECT 1 FROM tbl r0 WHERE r0."schema" = :p0_schema AND JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.caseRef\')) = t.uuid AND r0.deleted IS NULL AND (r0.o = 1) AND JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.pd\')) = :p0_c0 AND (CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.v\')) REGEXP \'^-?[0-9]+(\\.[0-9]+)?$\' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.v\')) AS DECIMAL(65,30)) >= :p0_c1 ELSE FALSE END) AND JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.d\')) < :p0_c2 AND JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.n\')) != :p0_c3 AND JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.o\'\'k\')) IN (:p0_c4_0, :p0_c4_1) AND 1 = 0 AND JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.arr\')) > :p0_c6 AND (CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.neg\')) REGEXP \'^-?[0-9]+(\\.[0-9]+)?$\' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.neg\')) AS DECIMAL(65,30)) <= :p0_c7 ELSE FALSE END))'],
			'mysql json forward' => ['mysql', 'json', 'forward', 'EXISTS (SELECT 1 FROM tbl r0 WHERE r0."schema" = :p0_schema AND r0.uuid = JSON_UNQUOTE(JSON_EXTRACT(t.object, \'$.caseRef\')) AND r0.deleted IS NULL AND (r0.o = 1) AND JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.pd\')) = :p0_c0 AND (CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.v\')) REGEXP \'^-?[0-9]+(\\.[0-9]+)?$\' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.v\')) AS DECIMAL(65,30)) >= :p0_c1 ELSE FALSE END) AND JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.d\')) < :p0_c2 AND JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.n\')) != :p0_c3 AND JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.o\'\'k\')) IN (:p0_c4_0, :p0_c4_1) AND 1 = 0 AND JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.arr\')) > :p0_c6 AND (CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.neg\')) REGEXP \'^-?[0-9]+(\\.[0-9]+)?$\' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.neg\')) AS DECIMAL(65,30)) <= :p0_c7 ELSE FALSE END))'],
			'mysql columns inverse' => ['mysql', 'columns', 'inverse', 'EXISTS (SELECT 1 FROM tbl r0 WHERE r0."case_ref" = t._uuid AND r0._deleted IS NULL AND (r0.o = 1) AND r0."pd" = :p0_c0 AND r0."v" >= :p0_c1 AND r0."d" < :p0_c2 AND r0."n" != :p0_c3 AND 1 = 0 AND r0."arr" > :p0_c5 AND r0."neg" <= :p0_c6)'],
			'mysql columns forward' => ['mysql', 'columns', 'forward', 'EXISTS (SELECT 1 FROM tbl r0 WHERE r0._uuid = t."case_ref" AND r0._deleted IS NULL AND (r0.o = 1) AND r0."pd" = :p0_c0 AND r0."v" >= :p0_c1 AND r0."d" < :p0_c2 AND r0."n" != :p0_c3 AND 1 = 0 AND r0."arr" > :p0_c5 AND r0."neg" <= :p0_c6)'],
		];
	}//end clauses()

	/**
	 * The conditions every golden clause carries.
	 *
	 * @param string $storage The storage shape; the column storage gets no quoted field name.
	 *
	 * @return array<int, array{field: string, operator: string, value: mixed}> The conditions.
	 */
	private function goldenConditions(string $storage): array {
		$conditions = [
			['field' => 'pd', 'operator' => 'eq', 'value' => 'pd-7'],
			['field' => 'v', 'operator' => 'gte', 'value' => '100'],
			['field' => 'd', 'operator' => 'lt', 'value' => '2026-06-01'],
			['field' => 'n', 'operator' => 'ne', 'value' => '3'],
			['field' => "o'k", 'operator' => 'in', 'value' => ['a', 2]],
			['field' => 'e', 'operator' => 'in', 'value' => []],
			['field' => 'arr', 'operator' => 'gt', 'value' => [1]],
			['field' => 'neg', 'operator' => 'lte', 'value' => '-1.5'],
		];

		if ($storage === RelatedRowExistsClause::STORAGE_COLUMNS) {
			unset($conditions[4]);
		}

		return array_values($conditions);
	}//end goldenConditions()

	/**
	 * The clause renders byte-identically, with the bindings in the same order.
	 *
	 * @param string $engine    The engine.
	 * @param string $storage   The storage shape.
	 * @param string $direction The direction.
	 * @param string $sql       The expected SQL.
	 *
	 * @return void
	 */
	#[DataProvider('clauses')]
	public function testTheClauseRendersTheExactSql(string $engine, string $storage, string $direction, string $sql): void {
		$rendered = (new RelatedRowExistsClause())->render(
			filter: new RelatedRowFilter('cp', 'caseRef', $this->goldenConditions(storage: $storage), $direction),
			target: new RelatedRowSqlTarget(engine: $engine, table: 'tbl', outerAlias: 't', storage: $storage),
			inner: new RelatedRowInnerRow(alias: 'r0', accessPredicate: 'r0.o = 1', parameterPrefix: 'p0')
		);

		$expected = [
			'p0_schema' => 'cp',
			'p0_c0' => 'pd-7',
			'p0_c1' => '100',
			'p0_c2' => '2026-06-01',
			'p0_c3' => '3',
			'p0_c4_0' => 'a',
			'p0_c4_1' => '2',
			'p0_c6' => [1],
			'p0_c7' => '-1.5',
		];
		if ($storage === RelatedRowExistsClause::STORAGE_COLUMNS) {
			$expected = [
				'p0_c0' => 'pd-7',
				'p0_c1' => '100',
				'p0_c2' => '2026-06-01',
				'p0_c3' => '3',
				'p0_c5' => [1],
				'p0_c6' => '-1.5',
			];
		}

		$this->assertSame($sql, $rendered['sql']);
		$this->assertSame($expected, $rendered['parameters']);
	}//end testTheClauseRendersTheExactSql()

	/**
	 * Every refusal of the clause, with its exact message.
	 *
	 * @return array<string, array{0: RelatedRowFilter, 1: RelatedRowSqlTarget, 2: string, 3: string}> Filter, target, predicate, message.
	 */
	public static function clauseRefusals(): array {
		$gte = [['field' => 'v', 'operator' => 'gte', 'value' => '1']];
		$like = [['field' => 'v', 'operator' => 'like', 'value' => '1']];

		return [
			'blank access predicate' => [
				new RelatedRowFilter('cp', 'k', []),
				new RelatedRowSqlTarget(engine: 'pgsql', table: 'tbl', outerAlias: 't'),
				'  ',
				'A related-row subquery needs the access predicate for the related schema. '
				. 'Without it the existence of rows the caller may not read decides which objects they see.',
			],
			'blank predicate wins over unknown storage' => [
				new RelatedRowFilter('cp', 'k', []),
				new RelatedRowSqlTarget(engine: 'pgsql', table: 'tbl', outerAlias: 't', storage: 'weird'),
				'',
				'A related-row subquery needs the access predicate for the related schema. '
				. 'Without it the existence of rows the caller may not read decides which objects they see.',
			],
			'unknown engine on json' => [
				new RelatedRowFilter('cp', 'k', $gte),
				new RelatedRowSqlTarget(engine: 'oracle', table: 'tbl', outerAlias: 't'),
				'x',
				"No related-row SQL for engine 'oracle'.",
			],
			'unknown storage' => [
				new RelatedRowFilter('cp', 'k', []),
				new RelatedRowSqlTarget(engine: 'pgsql', table: 'tbl', outerAlias: 't', storage: 'weird'),
				'x',
				"No related-row SQL for storage 'weird'.",
			],
			'unknown operator on json' => [
				new RelatedRowFilter('cp', 'k', $like),
				new RelatedRowSqlTarget(engine: 'pgsql', table: 'tbl', outerAlias: 't'),
				'x',
				"No SQL for operator 'like'.",
			],
			'unknown operator on columns' => [
				new RelatedRowFilter('cp', 'k', $like),
				new RelatedRowSqlTarget(engine: 'pgsql', table: 'tbl', outerAlias: 't', storage: 'columns'),
				'x',
				"No SQL for operator 'like'.",
			],
		];
	}//end clauseRefusals()

	/**
	 * Each refusal of the clause keeps its message, and which one wins.
	 *
	 * @param RelatedRowFilter    $filter    The filter.
	 * @param RelatedRowSqlTarget $target    The target.
	 * @param string              $predicate The access predicate.
	 * @param string              $message   The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('clauseRefusals')]
	public function testTheClauseRefusesWithTheExactMessage(
		RelatedRowFilter $filter,
		RelatedRowSqlTarget $target,
		string $predicate,
		string $message
	): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		(new RelatedRowExistsClause())->render(
			filter: $filter,
			target: $target,
			inner: new RelatedRowInnerRow(alias: 'r0', accessPredicate: $predicate, parameterPrefix: 'p')
		);
	}//end testTheClauseRefusesWithTheExactMessage()

	/**
	 * A typed column renders plainly even on an engine the JSON path does not know.
	 *
	 * @return void
	 */
	public function testAColumnComparisonNeedsNoEngine(): void {
		$rendered = (new RelatedRowExistsClause())->render(
			filter: new RelatedRowFilter('cp', 'k', [['field' => 'v', 'operator' => 'gte', 'value' => '1']]),
			target: new RelatedRowSqlTarget(engine: 'oracle', table: 'tbl', outerAlias: 't', storage: 'columns'),
			inner: new RelatedRowInnerRow(alias: 'r0', accessPredicate: 'x', parameterPrefix: 'p')
		);

		$this->assertSame('EXISTS (SELECT 1 FROM tbl r0 WHERE r0."k" = t._uuid AND r0._deleted IS NULL AND (x) AND r0."v" >= :p_c0)', $rendered['sql']);
		$this->assertSame(['p_c0' => '1'], $rendered['parameters']);
	}//end testAColumnComparisonNeedsNoEngine()

	/**
	 * A schema with two properties.
	 *
	 * @param int $id The id.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setProperties(['case' => ['type' => 'string'], 'value' => ['type' => 'number'], 'ref' => ['type' => 'string']]);

		return $schema;
	}//end schema()

	/**
	 * An applier that logs every collaborator call, in order, into $log.
	 *
	 * @param array<int, string>           $log      The shared call log.
	 * @param array<string, array<Schema>> $bySlug   What findBySlug returns per slug.
	 * @param array<int, bool>             $tables   Whether the table exists, per schema id.
	 * @param bool                         $readable What the property guard answers.
	 * @param Schema|null                  $byId     What find(id) returns; null throws.
	 *
	 * @return RelatedRowQueryApplier The applier.
	 */
	private function loggingApplier(array &$log, array $bySlug, array $tables, bool $readable=true, ?Schema $byId=null): RelatedRowQueryApplier {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findBySlug')->willReturnCallback(
			static function (string $slug) use (&$log, $bySlug): array {
				$log[] = 'findBySlug ' . $slug;
				return ($bySlug[$slug] ?? []);
			}
		);
		$schemaMapper->method('find')->willReturnCallback(
			static function (int $id) use (&$log, $byId): Schema {
				$log[] = 'find ' . $id;
				if ($byId === null) {
					throw new RuntimeException('none');
				}

				return $byId;
			}
		);

		$tableHandler = $this->createMock(MagicTableHandler::class);
		$tableHandler->method('getTableNameForRegisterSchema')->willReturnCallback(
			static function (Register $register, Schema $schema) use (&$log): string {
				$log[] = 'tableName ' . $schema->getId();
				return 'openregister_table_' . $register->getId() . '_' . $schema->getId();
			}
		);
		$tableHandler->method('existsTableForRegisterSchema')->willReturnCallback(
			static function (Register $register, Schema $schema) use (&$log, $tables): bool {
				$log[] = 'tableExists ' . $schema->getId();
				return ($tables[$schema->getId()] ?? true);
			}
		);

		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->method('buildRbacPredicateForAlias')->willReturnCallback(
			static function (Schema $schema, string $alias, string $action, ?string $tableName) use (&$log): string {
				$log[] = 'rbac ' . $alias . ' ' . $action . ' ' . $tableName;
				return $alias . '._owner = \'alice\'';
			}
		);

		$guard = $this->createMock(PropertyReadGuard::class);
		$guard->method('mayRead')->willReturnCallback(
			static function (Schema $schema, string $property) use (&$log, $readable): bool {
				$log[] = 'mayRead ' . $property;
				return $readable;
			}
		);

		return new RelatedRowQueryApplier(
			lookups: new RelatedRowLookups(schemaMapper: $schemaMapper, tableHandler: $tableHandler),
			db: $this->createMock(IDBConnection::class),
			logger: new NullLogger(),
			access: new RelatedRowAccess(rbac: $rbac, logger: new NullLogger(), guard: $guard)
		);
	}//end loggingApplier()

	/**
	 * A query builder that logs every call, in order, into $log.
	 *
	 * @param array<int, string> $log The shared call log.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function loggingBuilder(array &$log): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createFunction')->willReturnCallback(
			static function (string $text) use (&$log): string {
				$log[] = 'createFunction ' . $text;
				return $text;
			}
		);
		$qb->method('setParameter')->willReturnCallback(
			static function (string $name, mixed $value) use (&$log, $qb): IQueryBuilder {
				$log[] = 'setParameter ' . $name . '=' . json_encode($value);
				return $qb;
			}
		);
		$qb->method('andWhere')->willReturnCallback(
			static function (mixed $where) use (&$log, $qb): IQueryBuilder {
				$log[] = 'andWhere';
				return $qb;
			}
		);

		return $qb;
	}//end loggingBuilder()

	/**
	 * A register.
	 *
	 * @return Register The register.
	 */
	private function register(): Register {
		$register = new Register();
		$register->setId(1);

		return $register;
	}//end register()

	/**
	 * The full call sequence for an inverse block, a forward block and a block without a table.
	 *
	 * @return void
	 */
	public function testTheApplierTouchesTheQueryInTheSameOrder(): void {
		$log     = [];
		$applier = $this->loggingApplier(
			log: $log,
			bySlug: ['cp' => [$this->schema(id: 2)], 'gone' => [$this->schema(id: 3)], 'fw' => [$this->schema(id: 4)]],
			tables: [3 => false]
		);
		$qb      = $this->loggingBuilder(log: $log);

		$applied = $applier->apply(
			qb: $qb,
			query: [
				'_referenced' => ['fw' => ['ref' => ['case' => 'c-1']]],
				'_related' => ['cp' => ['case' => ['value' => ['gte' => '100']]], 'gone' => ['case' => ['value' => 1]]],
			],
			register: $this->register(),
			organisationScopeFor: static function (Schema $schema, int $registerId) use (&$log): array {
				$log[] = 'scope ' . $schema->getId() . ' ' . $registerId;
				return ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a']];
			}
		);

		$this->assertSame(3, $applied);
		$this->assertSame(
			[
				'findBySlug cp',
				'tableName 2',
				'tableExists 2',
				'mayRead value',
				'mayRead case',
				'scope 2 1',
				'rbac rel0 read openregister_table_1_2',
				'setParameter rel0_org0="org-a"',
				'setParameter rel0_c0="100"',
				'createFunction EXISTS (SELECT 1 FROM *PREFIX*openregister_table_1_2 rel0 WHERE rel0."case" = t._uuid AND rel0._deleted IS NULL AND '
				. "((rel0._owner = 'alice') AND rel0._organisation IN (:rel0_org0)) AND rel0.\"value\" >= :rel0_c0)",
				'andWhere',
				'findBySlug gone',
				'tableName 3',
				'tableExists 3',
				'createFunction 1 = 0',
				'andWhere',
				'findBySlug fw',
				'tableName 4',
				'tableExists 4',
				'mayRead case',
				'scope 4 1',
				'rbac rel2 read openregister_table_1_4',
				'setParameter rel2_org0="org-a"',
				'setParameter rel2_c0="c-1"',
				'createFunction EXISTS (SELECT 1 FROM *PREFIX*openregister_table_1_4 rel2 WHERE rel2._uuid = t."ref" AND rel2._deleted IS NULL AND '
				. "((rel2._owner = 'alice') AND rel2._organisation IN (:rel2_org0)) AND rel2.\"case\" = :rel2_c0)",
				'andWhere',
			],
			$log
		);
	}//end testTheApplierTouchesTheQueryInTheSameOrder()

	/**
	 * Refused: an unreadable property ends the query before the scope, the predicate or the builder is touched.
	 *
	 * @return void
	 */
	public function testAnUnreadablePropertyIsRefusedBeforeAnythingIsAdded(): void {
		$log     = [];
		$applier = $this->loggingApplier(log: $log, bySlug: ['cp' => [$this->schema(id: 2)]], tables: [], readable: false);
		$qb      = $this->loggingBuilder(log: $log);

		try {
			$applier->apply(
				qb: $qb,
				query: ['_related' => ['cp' => ['case' => ['value' => ['gte' => '100']]]]],
				register: $this->register(),
				organisationScopeFor: static function () use (&$log): array {
					$log[] = 'scope';
					return ['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => []];
				}
			);
			$this->fail('An unreadable property must be refused.');
		} catch (InvalidArgumentException $refusal) {
			$this->assertSame("'value' is not a property of schema 'cp' that a related-row filter can match on.", $refusal->getMessage());
		}

		$this->assertSame(['findBySlug cp', 'tableName 2', 'tableExists 2', 'mayRead value'], $log);
	}//end testAnUnreadablePropertyIsRefusedBeforeAnythingIsAdded()

	/**
	 * Refused: an unreadable property on a later block leaves the earlier clause and adds nothing after it.
	 *
	 * @return void
	 */
	public function testALaterRefusalStopsAfterTheEarlierClause(): void {
		$log     = [];
		$applier = $this->loggingApplier(log: $log, bySlug: ['cp' => [$this->schema(id: 2)]], tables: []);
		$qb      = $this->loggingBuilder(log: $log);

		try {
			$applier->apply(
				qb: $qb,
				query: ['_related' => ['cp' => ['case' => [0 => ['value' => '1'], 1 => ['_owner' => 'x']]]]],
				register: $this->register(),
				organisationScopeFor: static fn (): array => ['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => []]
			);
			$this->fail('A metadata column must be refused.');
		} catch (InvalidArgumentException $refusal) {
			$this->assertSame("'_owner' is not a property of schema 'cp' that a related-row filter can match on.", $refusal->getMessage());
		}

		$this->assertSame(1, count(array_keys($log, 'andWhere', true)));
		$this->assertSame('findBySlug cp', $log[count($log) - 3]);
	}//end testALaterRefusalStopsAfterTheEarlierClause()

	/**
	 * A missing table answers nothing without consulting the access rules.
	 *
	 * @return void
	 */
	public function testAMissingTableMatchesNothingWithoutConsultingAccess(): void {
		$log     = [];
		$applier = $this->loggingApplier(log: $log, bySlug: ['cp' => [$this->schema(id: 2)]], tables: [2 => false], readable: false);
		$qb      = $this->loggingBuilder(log: $log);

		$applied = $applier->apply(
			qb: $qb,
			query: ['_related' => ['cp' => ['case' => ['value' => '1']]]],
			register: $this->register(),
			organisationScopeFor: static fn (): array => ['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => []]
		);

		$this->assertSame(1, $applied);
		$this->assertSame(['findBySlug cp', 'tableName 2', 'tableExists 2', 'createFunction 1 = 0', 'andWhere'], $log);
	}//end testAMissingTableMatchesNothingWithoutConsultingAccess()

	/**
	 * Schema resolution: by numeric id when no slug matches, and each refusal.
	 *
	 * @return void
	 */
	public function testSchemaResolutionOutcomes(): void {
		$log     = [];
		$applier = $this->loggingApplier(log: $log, bySlug: [], tables: [7 => false], byId: $this->schema(id: 7));
		$this->assertSame(
			1,
			$applier->apply(
				qb: $this->loggingBuilder(log: $log),
				query: ['_related' => ['7' => ['case' => ['value' => '1']]]],
				register: $this->register(),
				organisationScopeFor: static fn (): array => []
			)
		);
		$this->assertSame(['findBySlug 7', 'find 7', 'tableName 7', 'tableExists 7', 'createFunction 1 = 0', 'andWhere'], $log);

		$cases = [
			'ambiguous' => [['cp' => [$this->schema(id: 2), $this->schema(id: 3)]], 'cp', "More than one schema is called 'cp', so the related-row filter is ambiguous."],
			'unknown id' => [[], '9', "There is no schema called '9' to filter related rows on."],
			'unknown slug' => [[], 'nope', "There is no schema called 'nope' to filter related rows on."],
		];
		foreach ($cases as $label => [$bySlug, $name, $message]) {
			$log = [];
			try {
				$this->loggingApplier(log: $log, bySlug: $bySlug, tables: [])->apply(
					qb: $this->loggingBuilder(log: $log),
					query: ['_related' => [$name => ['case' => ['value' => '1']]]],
					register: $this->register(),
					organisationScopeFor: static fn (): array => []
				);
				$this->fail($label . ' must be refused.');
			} catch (InvalidArgumentException $refusal) {
				$this->assertSame($message, $refusal->getMessage(), $label);
			}

			$this->assertNotContains('andWhere', $log, $label);
		}
	}//end testSchemaResolutionOutcomes()

	/**
	 * No related key: nothing is looked up and the builder is untouched.
	 *
	 * @return void
	 */
	public function testNoBlockTouchesNothing(): void {
		$log = [];
		$this->assertSame(
			0,
			$this->loggingApplier(log: $log, bySlug: [], tables: [])->apply(
				qb: $this->loggingBuilder(log: $log),
				query: ['other' => 1],
				register: $this->register(),
				organisationScopeFor: static fn (): array => []
			)
		);
		$this->assertSame([], $log);
	}//end testNoBlockTouchesNothing()
}//end class
