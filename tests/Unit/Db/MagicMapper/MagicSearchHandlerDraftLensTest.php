<?php

/**
 * The draft lens reaches both query paths of the search handler (decision 201).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-list-must-hide-drafts-unless-asked
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\DeletedLens;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
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
 * Decision 201: a list or search hides drafts unless asked, on the union path and the single-table path alike.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses \OCA\OpenRegister\Service\Object\DraftListLens
 * @uses \OCA\OpenRegister\Db\MagicMapper\DeletedLens
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\MagicMapper\NestedPathFilter
 */
final class MagicSearchHandlerDraftLensTest extends TestCase {

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
	 * Spec scenario: a work list leaves drafts out, with RBAC off, which is how an app's service lists.
	 */
	public function testTheUnionPathHidesDraftsByDefault(): void {
		$conditions = $this->handler(userId: null)->buildWhereConditionsSql(query: ['_rbac' => false], schema: new Schema());

		$this->assertContains("(_status IS NULL OR _status <> 'draft')", $conditions);
	}//end testTheUnionPathHidesDraftsByDefault()

	/**
	 * Spec scenario: the owner still sees their own drafts in the same list.
	 */
	public function testTheUnionPathKeepsTheCallersOwnDrafts(): void {
		$conditions = $this->handler(userId: 'alice')->buildWhereConditionsSql(query: [], schema: new Schema());

		$this->assertContains("((_status IS NULL OR _status <> 'draft') OR _owner = 'alice')", $conditions);
	}//end testTheUnionPathKeepsTheCallersOwnDrafts()

	/**
	 * Decision 216: a schema with `draftsListed: false` keeps the caller's own drafts out, on both paths.
	 */
	public function testASchemaKeepsOwnDraftsOutOnBothPaths(): void {
		$quiet = new Schema();
		$quiet->setConfiguration(['draftsListed' => false]);

		// The union path: no owner admit, although alice is signed in.
		$conditions = $this->handler(userId: 'alice')->buildWhereConditionsSql(query: [], schema: $quiet);
		$this->assertContains("(_status IS NULL OR _status <> 'draft')", $conditions);
		$this->assertNotContains("((_status IS NULL OR _status <> 'draft') OR _owner = 'alice')", $conditions);

		// Asking still shows them.
		$this->assertContains("_status = 'draft'", $this->handler(userId: 'alice')->buildWhereConditionsSql(query: ['_drafts' => 'true'], schema: $quiet));

		// The single-table path.
		$apply = (new ReflectionClass(MagicSearchHandler::class))->getMethod('applyBasicFilters');
		$apply->setAccessible(true);
		$this->functions = [];
		$handler = $this->handler(userId: 'alice');
		$queryBuilder = (new ReflectionClass(MagicSearchHandler::class))->getProperty('db')->getValue($handler)->getQueryBuilder();
		$apply->invoke($handler, $queryBuilder, DeletedLens::ANY, MagicSearchHandler::ARCHIVED_EXCLUDE, [], $quiet);
		$this->assertSame(["(t._status IS NULL OR t._status <> 'draft')"], $this->functions);
	}//end testASchemaKeepsOwnDraftsOutOnBothPaths()

	/**
	 * Spec scenario: the filter shows them; `_drafts=true` alone, `_drafts=any` beside the rest.
	 */
	public function testTheUnionPathShowsDraftsWhenAsked(): void {
		$only = $this->handler(userId: 'alice')->buildWhereConditionsSql(query: ['_drafts' => 'true'], schema: new Schema());
		$this->assertContains("_status = 'draft'", $only);

		// The control: a path that always emitted a clause would pass the tests above.
		foreach ([['_drafts' => 'any'], ['@self' => ['status' => 'draft']]] as $query) {
			$any = $this->handler(userId: 'alice')->buildWhereConditionsSql(query: $query, schema: new Schema());
			$this->assertSame([], array_values(array_filter($any, static fn (mixed $condition): bool => is_string($condition) === true && str_contains($condition, "'draft')") === true)));
			$this->assertNotContains("_status = 'draft'", $any);
		}
	}//end testTheUnionPathShowsDraftsWhenAsked()

	/**
	 * The single-table path carries the same lens, with the owner bound and the table alias on each column.
	 *
	 * Two paths build one WHERE by different means; a clause on one of them
	 * only is a list that shows drafts or hides them depending on how many
	 * schemas the caller asked for.
	 */
	public function testTheSingleTablePathCarriesTheSameLens(): void {
		$apply = (new ReflectionClass(MagicSearchHandler::class))->getMethod('applyBasicFilters');
		$apply->setAccessible(true);
		$calls = [
			"((t._status IS NULL OR t._status <> 'draft') OR t._owner = :bound_alice)" => ['alice', []],
			"(t._status IS NULL OR t._status <> 'draft')" => [null, []],
			"t._status = 'draft'" => ['alice', ['_drafts' => true]],
		];
		foreach ($calls as $expected => [$userId, $query]) {
			$this->functions = [];
			$handler = $this->handler(userId: $userId);
			$queryBuilder = (new ReflectionClass(MagicSearchHandler::class))->getProperty('db')->getValue($handler)->getQueryBuilder();
			$apply->invoke($handler, $queryBuilder, DeletedLens::ANY, MagicSearchHandler::ARCHIVED_EXCLUDE, $query);

			$this->assertSame([$expected], $this->functions);
		}

		$this->functions = [];
		$handler = $this->handler(userId: 'alice');
		$queryBuilder = (new ReflectionClass(MagicSearchHandler::class))->getProperty('db')->getValue($handler)->getQueryBuilder();
		$apply->invoke($handler, $queryBuilder, DeletedLens::ANY, MagicSearchHandler::ARCHIVED_EXCLUDE, ['_drafts' => 'any']);
		$this->assertSame([], $this->functions);
	}//end testTheSingleTablePathCarriesTheSameLens()

	/**
	 * `_drafts` is reserved, so it is never read as a filter on a property called "drafts" (which answers `1 = 0`).
	 */
	public function testDraftsIsAReservedParameter(): void {
		$method = (new ReflectionClass(MagicSearchHandler::class))->getMethod('getReservedParams');
		$method->setAccessible(true);

		$this->assertContains('_drafts', $method->invoke($this->handler(userId: null)));
	}//end testDraftsIsAReservedParameter()
}//end class
