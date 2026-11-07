<?php

/**
 * Golden pins for the related-row access path, written against the code before its parameter refactor.
 *
 * 🔴 THESE PIN ALLOWED AND REFUSED OUTCOMES BYTE FOR BYTE. A related-row
 * subquery is a second place rows can leak: the access predicate, the
 * organisation boundary, the soft-delete term and the refusals all decide
 * which rows of the related schema are consulted. A refactor of how the
 * renderer and the applier receive their arguments must leave every rendered
 * clause, every bound parameter and every refusal exactly as it was.
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
use OCA\OpenRegister\Service\Query\RelatedRowAccess;
use OCA\OpenRegister\Service\Query\RelatedRowExistsClause;
use OCA\OpenRegister\Service\Query\RelatedRowFilter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Golden pins: `RelatedRowExistsClause`, `RelatedRowAccess`, `RelatedRowQueryApplier`.
 *
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowExistsClause
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowFilter
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowFilterParser
 * @uses \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 */
class RelatedRowAccessGoldenTest extends TestCase {

	/**
	 * The stock row predicate the RBAC mock returns.
	 */
	private const ROW_PREDICATE = "r0._owner = 'alice'";

	/**
	 * What the RBAC mock was asked, one entry per call.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $rbacCalls = [];

	/**
	 * What the organisation-scope callback was asked, one entry per call.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $scopeCalls = [];

	/**
	 * A filter exercising every condition shape: numeric and text ordering, `in`, `ne`, empty `in`.
	 *
	 * @return RelatedRowFilter The filter.
	 */
	private function everyShapeFilter(): RelatedRowFilter {
		return new RelatedRowFilter(
			schema: 'caseProperty',
			foreignKey: 'case',
			conditions: [
				['field' => 'value', 'operator' => 'gte', 'value' => '100'],
				['field' => 'value', 'operator' => 'lt', 'value' => '2026-06-01'],
				['field' => 'kind', 'operator' => 'in', 'value' => ['a', 'b']],
				['field' => 'kind', 'operator' => 'ne', 'value' => 'x'],
				['field' => 'tags', 'operator' => 'in', 'value' => []],
			]
		);
	}//end everyShapeFilter()

	/**
	 * A forward (`_referenced`) block on the object's `subsidieaanvraag` property.
	 *
	 * @return RelatedRowFilter The filter.
	 */
	private function forwardFilter(): RelatedRowFilter {
		return new RelatedRowFilter(
			schema: 'aanvraag',
			foreignKey: 'subsidieaanvraag',
			conditions: [['field' => 'case', 'operator' => 'eq', 'value' => 'case-1']],
			direction: RelatedRowFilter::DIRECTION_FORWARD
		);
	}//end forwardFilter()

	/**
	 * The organisation scope that admits every organisation.
	 *
	 * @return array{mode: string, uuids: array<int, string>} The scope.
	 */
	private function allScope(): array {
		return ['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => []];
	}//end allScope()

	/**
	 * Render one clause through the renderer's public entry point.
	 *
	 * @param RelatedRowFilter $filter          The filter.
	 * @param string           $engine          The engine.
	 * @param string           $storage         The storage.
	 * @param string           $accessPredicate The access predicate.
	 *
	 * @return array{sql: string, parameters: array<string, mixed>} The clause.
	 */
	private function renderOne(
		RelatedRowFilter $filter,
		string $engine,
		string $storage,
		string $accessPredicate=self::ROW_PREDICATE
	): array {
		return (new RelatedRowExistsClause())->render(
			filter: $filter,
			engine: $engine,
			table: 'oc_t',
			outerAlias: 'o',
			innerAlias: 'r0',
			accessPredicate: $accessPredicate,
			parameterPrefix: 'rel0',
			storage: $storage
		);
	}//end renderOne()

	/**
	 * Render every filter through the renderer's batch entry point.
	 *
	 * @param array<int, RelatedRowFilter> $filters The filters.
	 * @param callable                     $access  The access predicate per alias and filter.
	 *
	 * @return array{sql: array<int, string>, parameters: array<string, mixed>} The clauses.
	 */
	private function renderMany(array $filters, callable $access): array {
		return (new RelatedRowExistsClause())->renderAll(
			filters: $filters,
			engine: RelatedRowExistsClause::ENGINE_POSTGRES,
			table: 'oc_objects',
			outerAlias: 'o',
			accessPredicateFor: $access
		);
	}//end renderMany()

	/**
	 * The combined predicate for one scope, straight from `RelatedRowAccess`.
	 *
	 * @param IQueryBuilder                                  $qb    The query.
	 * @param array{mode: string, uuids: array<int, string>} $scope The scope.
	 *
	 * @return string The predicate.
	 */
	private function predicateFor(IQueryBuilder $qb, array $scope): string {
		return $this->access()->predicate(
			qb: $qb,
			schema: $this->schema(),
			alias: 'rel0',
			tableName: 'openregister_table_1_2',
			scope: $scope
		);
	}//end predicateFor()

	/**
	 * The access rules over a recording RBAC mock.
	 *
	 * @return RelatedRowAccess The access rules.
	 */
	private function access(): RelatedRowAccess {
		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->method('buildRbacPredicateForAlias')->willReturnCallback(function (...$args): string {
			$this->rbacCalls[] = $args;
			return self::ROW_PREDICATE;
		});

		return new RelatedRowAccess(rbac: $rbac, logger: new NullLogger());
	}//end access()

	/**
	 * An applier whose lookups answer as given.
	 *
	 * @param array<int, Schema> $found       What findBySlug returns.
	 * @param bool               $tableExists Whether the related table exists.
	 *
	 * @return RelatedRowQueryApplier The applier.
	 */
	private function applier(array $found, bool $tableExists=true): RelatedRowQueryApplier {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findBySlug')->willReturn($found);
		$schemaMapper->method('find')->willThrowException(new \RuntimeException('none'));

		$tableHandler = $this->createMock(MagicTableHandler::class);
		$tableHandler->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_2');
		$tableHandler->method('existsTableForRegisterSchema')->willReturn($tableExists);

		return new RelatedRowQueryApplier(
			schemaMapper: $schemaMapper,
			tableHandler: $tableHandler,
			db: $this->createMock(IDBConnection::class),
			logger: new NullLogger(),
			access: $this->access()
		);
	}//end applier()

	/**
	 * Run the applier and record what it put on the query.
	 *
	 * @param RelatedRowQueryApplier $applier The applier.
	 * @param array<string, mixed>   $query   The request query.
	 * @param array<string, mixed>   $scope   The organisation scope the callback answers.
	 *
	 * @return array{applied: int, where: array<int, string>, parameters: array<string, mixed>} What happened.
	 */
	private function applyQuery(RelatedRowQueryApplier $applier, array $query, array $scope): array {
		$where      = [];
		$parameters = [];
		$qb         = $this->createMock(IQueryBuilder::class);
		$qb->method('createFunction')->willReturnCallback(static fn (string $text): string => $text);
		$qb->method('andWhere')->willReturnCallback(function (...$args) use (&$where, $qb) {
			$where[] = (string)$args[0];
			return $qb;
		});
		$qb->method('setParameter')->willReturnCallback(function (string $name, mixed $value) use (&$parameters, $qb) {
			$parameters[$name] = $value;
			return $qb;
		});

		$register = new Register();
		$register->setId(1);

		$applied = $applier->apply(
			qb: $qb,
			query: $query,
			register: $register,
			organisationScopeFor: function (...$args) use ($scope): array {
				$this->scopeCalls[] = $args;
				return $scope;
			}
		);

		return ['applied' => $applied, 'where' => $where, 'parameters' => $parameters];
	}//end applyQuery()

	/**
	 * The related schema.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(2);
		$schema->setProperties(['case' => ['type' => 'string'], 'value' => ['type' => 'number']]);

		return $schema;
	}//end schema()

	/**
	 * One `_related` block on a readable property.
	 *
	 * @param string $field The property the condition names.
	 *
	 * @return array<string, mixed> The query.
	 */
	private function relatedQuery(string $field='value'): array {
		return ['_related' => ['caseProperty' => ['case' => [$field => ['gte' => '100']]]]];
	}//end relatedQuery()

	/**
	 * The full clause the applier renders for the stock query under a given access predicate.
	 *
	 * @param string $access The access predicate as it appears in the clause.
	 *
	 * @return string The clause.
	 */
	private function appliedClause(string $access): string {
		return 'EXISTS (SELECT 1 FROM *PREFIX*openregister_table_1_2 rel0 WHERE rel0."case" = t._uuid'
			. ' AND rel0._deleted IS NULL AND (' . $access . ') AND rel0."value" >= :rel0_c0)';
	}//end appliedClause()

	/**
	 * Allowed: every engine and storage renders exactly the clause it rendered before, predicate inside.
	 *
	 * @return void
	 */
	public function testRenderIsByteIdenticalOnEveryEngineAndStorage(): void {
		$columns = 'EXISTS (SELECT 1 FROM oc_t r0 WHERE r0."case" = o._uuid AND r0._deleted IS NULL AND (r0._owner = \'alice\')'
			. ' AND r0."value" >= :rel0_c0 AND r0."value" < :rel0_c1 AND r0."kind" IN (:rel0_c2_0, :rel0_c2_1)'
			. ' AND r0."kind" != :rel0_c3 AND 1 = 0)';
		$columnParameters = ['rel0_c0' => '100', 'rel0_c1' => '2026-06-01', 'rel0_c2_0' => 'a', 'rel0_c2_1' => 'b', 'rel0_c3' => 'x'];
		$jsonParameters   = (['rel0_schema' => 'caseProperty'] + $columnParameters);

		$pg = 'EXISTS (SELECT 1 FROM oc_t r0 WHERE r0."schema" = :rel0_schema AND r0.object ->> \'case\' = o.uuid'
			. ' AND r0.deleted IS NULL AND (r0._owner = \'alice\')'
			. ' AND (CASE WHEN r0.object ->> \'value\' ~ \'^-?[0-9]+(\\.[0-9]+)?$\' THEN (r0.object ->> \'value\')::numeric >= :rel0_c0 ELSE FALSE END)'
			. ' AND r0.object ->> \'value\' < :rel0_c1 AND r0.object ->> \'kind\' IN (:rel0_c2_0, :rel0_c2_1)'
			. ' AND r0.object ->> \'kind\' != :rel0_c3 AND 1 = 0)';

		$my = 'EXISTS (SELECT 1 FROM oc_t r0 WHERE r0."schema" = :rel0_schema'
			. ' AND JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.case\')) = o.uuid AND r0.deleted IS NULL AND (r0._owner = \'alice\')'
			. ' AND (CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.value\')) REGEXP \'^-?[0-9]+(\\.[0-9]+)?$\''
			. ' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.value\')) AS DECIMAL(65,30)) >= :rel0_c0 ELSE FALSE END)'
			. ' AND JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.value\')) < :rel0_c1'
			. ' AND JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.kind\')) IN (:rel0_c2_0, :rel0_c2_1)'
			. ' AND JSON_UNQUOTE(JSON_EXTRACT(r0.object, \'$.kind\')) != :rel0_c3 AND 1 = 0)';

		$filter = $this->everyShapeFilter();

		$this->assertSame(
			['sql' => $pg, 'parameters' => $jsonParameters],
			$this->renderOne(filter: $filter, engine: RelatedRowExistsClause::ENGINE_POSTGRES, storage: RelatedRowExistsClause::STORAGE_JSON)
		);
		$this->assertSame(
			['sql' => $my, 'parameters' => $jsonParameters],
			$this->renderOne(filter: $filter, engine: RelatedRowExistsClause::ENGINE_MARIADB, storage: RelatedRowExistsClause::STORAGE_JSON)
		);
		$this->assertSame(
			['sql' => $columns, 'parameters' => $columnParameters],
			$this->renderOne(filter: $filter, engine: RelatedRowExistsClause::ENGINE_POSTGRES, storage: RelatedRowExistsClause::STORAGE_COLUMNS)
		);
		$this->assertSame(
			['sql' => $columns, 'parameters' => $columnParameters],
			$this->renderOne(filter: $filter, engine: RelatedRowExistsClause::ENGINE_MARIADB, storage: RelatedRowExistsClause::STORAGE_COLUMNS)
		);
	}//end testRenderIsByteIdenticalOnEveryEngineAndStorage()

	/**
	 * Allowed: a forward block joins the other way round, predicate still inside.
	 *
	 * @return void
	 */
	public function testForwardRenderIsByteIdentical(): void {
		$filter = $this->forwardFilter();

		$this->assertSame(
			[
				'sql'        => 'EXISTS (SELECT 1 FROM oc_t r0 WHERE r0._uuid = o."subsidieaanvraag" AND r0._deleted IS NULL'
					. ' AND (TRUE) AND r0."case" = :rel0_c0)',
				'parameters' => ['rel0_c0' => 'case-1'],
			],
			$this->renderOne(
				filter: $filter,
				engine: RelatedRowExistsClause::ENGINE_MARIADB,
				storage: RelatedRowExistsClause::STORAGE_COLUMNS,
				accessPredicate: 'TRUE'
			)
		);
	}//end testForwardRenderIsByteIdentical()

	/**
	 * Refused: an empty or blank access predicate, on both storages, before any SQL is produced.
	 *
	 * @return void
	 */
	public function testRenderRefusesAMissingAccessPredicate(): void {
		foreach ([RelatedRowExistsClause::STORAGE_JSON, RelatedRowExistsClause::STORAGE_COLUMNS] as $storage) {
			foreach (['', "   \n\t"] as $blank) {
				try {
					$this->renderOne(
						filter: $this->everyShapeFilter(),
						engine: RelatedRowExistsClause::ENGINE_POSTGRES,
						storage: $storage,
						accessPredicate: $blank
					);
					$this->fail('A blank access predicate must be refused on '.$storage.'.');
				} catch (InvalidArgumentException $e) {
					$this->assertStringContainsString('needs the access predicate', $e->getMessage());
				}
			}
		}
	}//end testRenderRefusesAMissingAccessPredicate()

	/**
	 * Refused: an unknown engine, an unknown storage and an unknown operator.
	 *
	 * @return void
	 */
	public function testRenderRefusesWhatItCannotSpell(): void {
		$numeric = new RelatedRowFilter(schema: 'caseProperty', foreignKey: 'case', conditions: [['field' => 'value', 'operator' => 'gt', 'value' => '5']]);
		$badOp   = new RelatedRowFilter(
			schema: 'caseProperty',
			foreignKey: 'case',
			conditions: [['field' => 'value', 'operator' => 'like', 'value' => '5']]
		);

		$cases = [
			'engine'   => [$numeric, 'sqlite', RelatedRowExistsClause::STORAGE_JSON, "No related-row SQL for engine 'sqlite'."],
			'storage'  => [$numeric, RelatedRowExistsClause::ENGINE_POSTGRES, 'csv', "No related-row SQL for storage 'csv'."],
			'operator' => [$badOp, RelatedRowExistsClause::ENGINE_POSTGRES, RelatedRowExistsClause::STORAGE_COLUMNS, "No SQL for operator 'like'."],
		];

		foreach ($cases as $label => [$filter, $engine, $storage, $message]) {
			try {
				$this->renderOne(filter: $filter, engine: $engine, storage: $storage);
				$this->fail('Expected a refusal for '.$label.'.');
			} catch (InvalidArgumentException $e) {
				$this->assertSame($message, $e->getMessage(), $label);
			}
		}
	}//end testRenderRefusesWhatItCannotSpell()

	/**
	 * Allowed: the batch renderer gives each block its own alias, prefix and predicate.
	 *
	 * @return void
	 */
	public function testRenderAllIsByteIdentical(): void {
		$forward = $this->forwardFilter();
		$asked   = [];

		$rendered = $this->renderMany(
			filters: [$forward, $this->everyShapeFilter()],
			access: function (string $alias, RelatedRowFilter $filter) use (&$asked): string {
				$asked[] = [$alias, $filter->schema];
				return $alias.".owner = '".$filter->schema."'";
			}
		);

		$this->assertSame([['rel0', 'aanvraag'], ['rel1', 'caseProperty']], $asked);
		$this->assertSame(
			[
				'sql'        => [
					'EXISTS (SELECT 1 FROM oc_objects rel0 WHERE rel0."schema" = :rel0_schema AND rel0.uuid = o.object ->> \'subsidieaanvraag\''
					. ' AND rel0.deleted IS NULL AND (rel0.owner = \'aanvraag\') AND rel0.object ->> \'case\' = :rel0_c0)',
					'EXISTS (SELECT 1 FROM oc_objects rel1 WHERE rel1."schema" = :rel1_schema AND rel1.object ->> \'case\' = o.uuid'
					. ' AND rel1.deleted IS NULL AND (rel1.owner = \'caseProperty\')'
					. ' AND (CASE WHEN rel1.object ->> \'value\' ~ \'^-?[0-9]+(\\.[0-9]+)?$\' THEN (rel1.object ->> \'value\')::numeric >= :rel1_c0 ELSE FALSE END)'
					. ' AND rel1.object ->> \'value\' < :rel1_c1 AND rel1.object ->> \'kind\' IN (:rel1_c2_0, :rel1_c2_1)'
					. ' AND rel1.object ->> \'kind\' != :rel1_c3 AND 1 = 0)',
				],
				'parameters' => [
					'rel0_schema' => 'aanvraag',
					'rel0_c0'     => 'case-1',
					'rel1_schema' => 'caseProperty',
					'rel1_c0'     => '100',
					'rel1_c1'     => '2026-06-01',
					'rel1_c2_0'   => 'a',
					'rel1_c2_1'   => 'b',
					'rel1_c3'     => 'x',
				],
			],
			$rendered
		);
	}//end testRenderAllIsByteIdentical()

	/**
	 * Refused: the batch renderer refuses a blank predicate for any one block.
	 *
	 * @return void
	 */
	public function testRenderAllRefusesABlankPredicateForAnyBlock(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->renderMany(
			filters: [$this->everyShapeFilter(), $this->everyShapeFilter()],
			access: static function (string $alias): string {
				if ($alias === 'rel1') {
					return ' ';
				}

				return 'TRUE';
			}
		);
	}//end testRenderAllRefusesABlankPredicateForAnyBlock()

	/**
	 * Allowed and refused per organisation scope, straight from the access rules.
	 *
	 * @return void
	 */
	public function testThePredicateForEveryScope(): void {
		$cases = [
			'all'        => [['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => ['org-a']], self::ROW_PREDICATE, []],
			'null-only'  => [
				['mode' => MagicOrganizationHandler::SCOPE_NULL_ONLY, 'uuids' => ['org-a']],
				'('.self::ROW_PREDICATE.') AND rel0._organisation IS NULL',
				[],
			],
			'in'         => [
				['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a', '', 7, 'org-b']],
				'('.self::ROW_PREDICATE.') AND rel0._organisation IN (:rel0_org0, :rel0_org1)',
				['rel0_org0' => 'org-a', 'rel0_org1' => 'org-b'],
			],
			'in-or-null' => [
				['mode' => MagicOrganizationHandler::SCOPE_IN_OR_NULL, 'uuids' => ['org-a']],
				'('.self::ROW_PREDICATE.') AND (rel0._organisation IN (:rel0_org0) OR rel0._organisation IS NULL)',
				['rel0_org0' => 'org-a'],
			],
			'none'       => [['mode' => MagicOrganizationHandler::SCOPE_NONE, 'uuids' => ['org-a']], '('.self::ROW_PREDICATE.') AND 1 = 0', []],
			'unknown'    => [['mode' => 'surprise', 'uuids' => ['org-a']], '('.self::ROW_PREDICATE.') AND 1 = 0', []],
			'no-mode'    => [['uuids' => ['org-a']], '('.self::ROW_PREDICATE.') AND 1 = 0', []],
			'in-empty'   => [['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['', null]], '('.self::ROW_PREDICATE.') AND 1 = 0', []],
		];

		foreach ($cases as $label => [$scope, $expected, $expectedParameters]) {
			$parameters      = [];
			$this->rbacCalls = [];
			$qb              = $this->createMock(IQueryBuilder::class);
			$qb->method('setParameter')->willReturnCallback(function (string $name, mixed $value) use (&$parameters, $qb) {
				$parameters[$name] = $value;
				return $qb;
			});

			$this->assertSame($expected, $this->predicateFor(qb: $qb, scope: $scope), $label);
			$this->assertSame($expectedParameters, $parameters, $label);
			$this->assertCount(1, $this->rbacCalls, $label);
			$this->assertSame(2, $this->rbacCalls[0][0]->getId(), $label);
			$this->assertSame(['rel0', 'read', 'openregister_table_1_2'], array_slice($this->rbacCalls[0], 1), $label);
		}
	}//end testThePredicateForEveryScope()

	/**
	 * Allowed: through the applier, each scope lands inside the clause; the callback and RBAC are asked about the RELATED schema.
	 *
	 * @return void
	 */
	public function testTheApplierRendersTheRelatedSchemasPredicateForEveryScope(): void {
		$cases = [
			'all'        => [['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => []], self::ROW_PREDICATE, []],
			'in-or-null' => [
				['mode' => MagicOrganizationHandler::SCOPE_IN_OR_NULL, 'uuids' => ['org-a', 'org-b']],
				'('.self::ROW_PREDICATE.') AND (rel0._organisation IN (:rel0_org0, :rel0_org1) OR rel0._organisation IS NULL)',
				['rel0_org0' => 'org-a', 'rel0_org1' => 'org-b'],
			],
			'none'       => [['mode' => MagicOrganizationHandler::SCOPE_NONE, 'uuids' => []], '('.self::ROW_PREDICATE.') AND 1 = 0', []],
		];

		foreach ($cases as $label => [$scope, $access, $orgParameters]) {
			$this->rbacCalls  = [];
			$this->scopeCalls = [];
			$related          = $this->schema();

			$outcome = $this->applyQuery(applier: $this->applier(found: [$related]), query: $this->relatedQuery(), scope: $scope);

			$this->assertSame(1, $outcome['applied'], $label);
			$this->assertSame([$this->appliedClause(access: $access)], $outcome['where'], $label);
			$this->assertSame(($orgParameters + ['rel0_c0' => '100']), $outcome['parameters'], $label);
			$this->assertCount(1, $this->scopeCalls, $label);
			$this->assertSame($related, $this->scopeCalls[0][0], $label);
			$this->assertSame(1, $this->scopeCalls[0][1], $label);
			$this->assertSame($related, $this->rbacCalls[0][0], $label);
			$this->assertSame(['rel0', 'read', 'openregister_table_1_2'], [$this->rbacCalls[0][1], $this->rbacCalls[0][2], $this->rbacCalls[0][3]], $label);
		}
	}//end testTheApplierRendersTheRelatedSchemasPredicateForEveryScope()

	/**
	 * Refused: an unreadable or unknown field ends the query before any clause or parameter is added.
	 *
	 * @return void
	 */
	public function testTheApplierRefusesAFieldThatIsNotAReadableProperty(): void {
		foreach (['salary', '_owner', '_organisation'] as $field) {
			$this->rbacCalls = [];
			$where           = [];
			try {
				$outcome = $this->applyQuery(
					applier: $this->applier(found: [$this->schema()]),
					query: $this->relatedQuery(field: $field),
					scope: $this->allScope()
				);
				$where   = $outcome['where'];
				$this->fail('A condition on '.$field.' must be refused.');
			} catch (InvalidArgumentException $e) {
				$this->assertSame(
					sprintf('\'%s\' is not a property of schema \'caseProperty\' that a related-row filter can match on.', $field),
					$e->getMessage()
				);
			}

			$this->assertSame([], $where, $field);
			$this->assertSame([], $this->rbacCalls, $field.': no predicate may be built for a refused block.');
		}
	}//end testTheApplierRefusesAFieldThatIsNotAReadableProperty()

	/**
	 * Refused: a schema nobody can name, or two that answer one name, ends the query.
	 *
	 * @return void
	 */
	public function testTheApplierRefusesASchemaItCannotResolve(): void {
		$cases = [
			'unknown'   => [[], "There is no schema called 'caseProperty' to filter related rows on."],
			'ambiguous' => [[$this->schema(), $this->schema()], "More than one schema is called 'caseProperty', so the related-row filter is ambiguous."],
		];

		foreach ($cases as $label => [$found, $message]) {
			try {
				$this->applyQuery(applier: $this->applier(found: $found), query: $this->relatedQuery(), scope: $this->allScope());
				$this->fail('Expected a refusal for '.$label.'.');
			} catch (InvalidArgumentException $e) {
				$this->assertSame($message, $e->getMessage(), $label);
			}
		}
	}//end testTheApplierRefusesASchemaItCannotResolve()

	/**
	 * Refused narrowly: a related schema without a table matches nothing, and no predicate is built.
	 *
	 * @return void
	 */
	public function testAMissingTableMatchesNothing(): void {
		$this->rbacCalls = [];

		$outcome = $this->applyQuery(
			applier: $this->applier(found: [$this->schema()], tableExists: false),
			query: $this->relatedQuery(),
			scope: $this->allScope()
		);

		$this->assertSame(['applied' => 1, 'where' => ['1 = 0'], 'parameters' => []], $outcome);
		$this->assertSame([], $this->rbacCalls);
	}//end testAMissingTableMatchesNothing()

	/**
	 * Allowed: no `_related` or `_referenced` key means nothing is looked up or added.
	 *
	 * @return void
	 */
	public function testAQueryWithoutBlocksIsUntouched(): void {
		$outcome = $this->applyQuery(applier: $this->applier(found: []), query: ['name' => 'x'], scope: $this->allScope());

		$this->assertSame(['applied' => 0, 'where' => [], 'parameters' => []], $outcome);
		$this->assertSame([], $this->scopeCalls);
	}//end testAQueryWithoutBlocksIsUntouched()
}//end class
