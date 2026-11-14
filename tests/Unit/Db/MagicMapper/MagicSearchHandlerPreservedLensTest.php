<?php

/**
 * OpenRegister - the preservation lens on the query.
 *
 * A preserved object leaves the working views as an archived one does, on a
 * schema that offers the regime, on the union path and the single-table
 * path alike; `_preserved=true` asks for them alone and `_preserved=any`
 * for both.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md#requirement-a-preservation-regime-sits-between-active-and-transferred-req-aps-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\DeletedLens;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\TableFilterRun;
use OCA\OpenRegister\Db\MagicMapper\WhereClauseRequest;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * REQ-APS-006: in the preservation state an object leaves the working views.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses \OCA\OpenRegister\Service\Object\DraftListLens
 * @uses \OCA\OpenRegister\Service\Object\PreservedListLens
 * @uses \OCA\OpenRegister\Service\Object\PreservedListing
 * @uses \OCA\OpenRegister\Db\MagicMapper\DeletedLens
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\MagicMapper\NestedPathFilter
 * @uses \OCA\OpenRegister\Service\OrganisationContext
 * @uses \OCA\OpenRegister\Db\MagicMapper\ConditionSqlRun
 * @uses \OCA\OpenRegister\Db\MagicMapper\DraftListRequest
 * @uses \OCA\OpenRegister\Db\MagicMapper\LifecycleColumns
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicReadTable
 * @uses \OCA\OpenRegister\Db\MagicMapper\OrganisationBoundary
 * @uses \OCA\OpenRegister\Db\MagicMapper\RbacAccessCall
 * @uses \OCA\OpenRegister\Db\MagicMapper\ReadPosture
 * @uses \OCA\OpenRegister\Db\MagicMapper\TableFilterRun
 * @uses \OCA\OpenRegister\Db\MagicMapper\WhereClauseRequest
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses \OCA\OpenRegister\Service\Object\DraftListing
 * @uses \OCA\OpenRegister\Service\Search\SearchGrammar
 * @uses \OCA\OpenRegister\Db\MagicMapper\QuotedSqlColumn
 */
final class MagicSearchHandlerPreservedLensTest extends TestCase {

	/**
	 * Raw SQL the single-table path put on its query builder.
	 *
	 * @var array<int, string>
	 */
	private array $functions = [];

	/**
	 * A handler over a fake database, enough to build a WHERE clause; RBAC and tenancy waved through.
	 *
	 * @param string|null $userId The signed-in caller, or null for nobody.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function handler(?string $userId): MagicSearchHandler {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn (mixed $value): string => "'" . $value . "'");

		$expression = $this->createMock(IExpressionBuilder::class);
		$expression->method('isNull')->willReturnCallback(static fn (string $column): string => $column . ' IS NULL');
		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$queryBuilder->method('getConnection')->willReturn($connection);
		$queryBuilder->method('expr')->willReturn($expression);
		$queryBuilder->method('createNamedParameter')->willReturnCallback(static fn (mixed $value): string => ':bound_' . $value);
		$queryBuilder->method('createFunction')->willReturnCallback(
			function (string $sql): IQueryFunction {
				$this->functions[] = $sql;

				return $this->createMock(IQueryFunction::class);
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);
		$db->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);

		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->method('buildRbacConditionsSql')->willReturn(['bypass' => true, 'conditions' => []]);
		$rbac->method('getCurrentUserId')->willReturn($userId);

		$organisation = $this->createMock(MagicOrganizationHandler::class);
		$organisation->method('resolveOrganizationScope')->willReturn(['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => []]);

		return new MagicSearchHandler(
			$db,
			$this->createMock(LoggerInterface::class),
			$rbac,
			$organisation,
			$this->createMock(SchemaTypeConverter::class),
			$this->createMock(DateTimeNormalizer::class),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class)
		);
	}//end handler()

	/**
	 * The working set, as the union path spells it.
	 */
	private const EXCLUDE_SQL = "COALESCE(LOWER(_tmlo::jsonb ->> 'archiefstatus'), '') NOT IN ('semi_static', 'semi_statisch', 'gearchiveerd')";

	/**
	 * The preserved lens, as the union path spells it.
	 */
	private const ONLY_SQL = "LOWER(_tmlo::jsonb ->> 'archiefstatus') IN ('semi_static', 'semi_statisch', 'gearchiveerd')";

	/**
	 * A schema that offers the preservation regime.
	 *
	 * @return Schema
	 */
	private static function preserving(): Schema {
		$schema = new Schema();
		$schema->setConfiguration([Schema::PRESERVE_ANNOTATION => ['enabled' => true]]);

		return $schema;
	}//end preserving()

	/**
	 * The union path's conditions for a query on a schema.
	 *
	 * @param array<string, mixed> $query  The query.
	 * @param Schema               $schema The schema.
	 *
	 * @return array<int|string, mixed>
	 */
	private function conditions(array $query, Schema $schema): array {
		return $this->handler(userId: null)->buildWhereClause(request: new WhereClauseRequest(query: $query, table: new MagicReadTable(schema: $schema)));
	}//end conditions()

	/**
	 * The conditions that read the archival status.
	 *
	 * @param array<int|string, mixed> $conditions The union path's conditions.
	 *
	 * @return list<string>
	 */
	private static function onStatus(array $conditions): array {
		return array_values(array_filter($conditions, static fn (mixed $condition): bool => is_string($condition) === true && str_contains($condition, 'archiefstatus') === true));
	}//end onStatus()

	/**
	 * Spec: a preserved object leaves the working views, without the caller asking.
	 */
	public function testTheUnionPathLeavesPreservedObjectsOutByDefault(): void {
		$this->assertSame([self::EXCLUDE_SQL], self::onStatus(conditions: $this->conditions(query: [], schema: self::preserving())));
	}//end testTheUnionPathLeavesPreservedObjectsOutByDefault()

	/**
	 * `_preserved=true` is the preserved lens alone; `_preserved=any` puts no condition on the list.
	 */
	public function testTheUnionPathShowsPreservedObjectsWhenAsked(): void {
		$this->assertSame([self::ONLY_SQL], self::onStatus(conditions: $this->conditions(query: ['_preserved' => 'true'], schema: self::preserving())));
		$this->assertSame([], self::onStatus(conditions: $this->conditions(query: ['_preserved' => 'any'], schema: self::preserving())));
	}//end testTheUnionPathShowsPreservedObjectsWhenAsked()

	/**
	 * A schema that does not offer the regime lists as before: no condition on the
	 * working set, and an empty preserved lens.
	 */
	public function testASchemaWithoutTheRegimeListsAsBefore(): void {
		$this->assertSame([], self::onStatus(conditions: $this->conditions(query: [], schema: new Schema())));
		$this->assertContains('1=0', $this->conditions(query: ['_preserved' => 'true'], schema: new Schema()));
	}//end testASchemaWithoutTheRegimeListsAsBefore()

	/**
	 * The single-table path carries the same lens, with the table alias on the column.
	 */
	public function testTheSingleTablePathCarriesTheSameLens(): void {
		$apply = (new ReflectionClass(MagicSearchHandler::class))->getMethod('applyBasicFilters');
		$apply->setAccessible(true);
		$calls = [
			[[], ["COALESCE(LOWER(t._tmlo::jsonb ->> 'archiefstatus'), '') NOT IN ('semi_static', 'semi_statisch', 'gearchiveerd')"]],
			[['_preserved' => true], ["LOWER(t._tmlo::jsonb ->> 'archiefstatus') IN ('semi_static', 'semi_statisch', 'gearchiveerd')"]],
			[['_preserved' => 'any'], []],
		];
		foreach ($calls as [$query, $expected]) {
			$this->functions = [];
			$handler = $this->handler(userId: null);
			$queryBuilder = (new ReflectionClass(MagicSearchHandler::class))->getProperty('db')->getValue($handler)->getQueryBuilder();
			$apply->invoke($handler, new TableFilterRun(qb: $queryBuilder, query: (['_deleted' => DeletedLens::ANY, '_drafts' => 'any'] + $query), table: new MagicReadTable(schema: self::preserving())));

			$this->assertSame($expected, $this->functions);
		}
	}//end testTheSingleTablePathCarriesTheSameLens()

	/**
	 * `_preserved` is reserved, so it is never read as a filter on a property called "preserved".
	 */
	public function testPreservedIsAReservedParameter(): void {
		$method = (new ReflectionClass(MagicSearchHandler::class))->getMethod('getReservedParams');
		$method->setAccessible(true);

		$this->assertContains('_preserved', $method->invoke((new ReflectionClass(MagicSearchHandler::class))->newInstanceWithoutConstructor()));
	}//end testPreservedIsAReservedParameter()
}//end class
