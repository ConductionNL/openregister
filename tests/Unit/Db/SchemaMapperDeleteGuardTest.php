<?php

/**
 * SchemaMapper::delete() object-count guard tests.
 *
 * Spec REQ (runtime-schema-api): "Runtime schema deletion is guarded by object count"
 *   — "The guard MUST be enforced at the mapper level (SchemaMapper::delete()), not only
 *     in the controller ... The mapper's object count MUST be taken from the magic tables."
 *
 * Regression context: the guard used to count rows in the retired `openregister_objects`
 * blob table, which is always empty for magic-table objects. It counted 0 and waved every
 * deletion through — including the AI-facing SchemasToolProvider / SchemaTool callers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for the repaired SchemaMapper::delete() guard.
 */
class SchemaMapperDeleteGuardTest extends TestCase {

	private const SCHEMA_ID = 42;

	private const REGISTER_ID = 7;

	/**
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection $db;

	/**
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher $eventDispatcher;

	private SchemaMapper $mapper;

	private Schema $schema;

	/**
	 * Wire the mapper up with mocked dependencies.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);

		$this->mapper = new SchemaMapper(
			$this->db,
			$this->eventDispatcher,
			$this->createMock(PropertyValidatorHandler::class),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->schema = new Schema();
		$this->schema->setSlug('cow');
		$this->schema->setTitle('Cow');

		$property = (new ReflectionClass($this->schema))->getProperty('id');
		$property->setAccessible(true);
		$property->setValue($this->schema, self::SCHEMA_ID);

	}//end setUp()

	/**
	 * Build a query-builder mock that answers "which registers hold schema 42".
	 *
	 * @return IQueryBuilder&MockObject The query builder.
	 */
	private function registerListQueryBuilder(): IQueryBuilder {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn(
			[
				[
					'id' => self::REGISTER_ID,
					'schemas' => '[42]',
				],
				[
					'id' => 8,
					'schemas' => '[99]',
				],
			]
		);

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$queryBuilder->method('select')->willReturnSelf();
		$queryBuilder->method('from')->willReturnSelf();
		$queryBuilder->method('executeQuery')->willReturn($result);

		return $queryBuilder;
	}//end registerListQueryBuilder()

	/**
	 * Build a query-builder mock that counts $count live rows in the magic table.
	 *
	 * @param int $count The row count to report.
	 *
	 * @return IQueryBuilder&MockObject The query builder.
	 */
	private function countQueryBuilder(int $count): IQueryBuilder {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($count);

		$function = $this->createMock(IFunctionBuilder::class);
		$function->method('count')->willReturn($this->createMock(IQueryFunction::class));

		$expression = $this->createMock(IExpressionBuilder::class);
		$expression->method('isNull')->willReturn('_deleted IS NULL');

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$queryBuilder->method('func')->willReturn($function);
		$queryBuilder->method('expr')->willReturn($expression);
		$queryBuilder->method('select')->willReturnSelf();
		$queryBuilder->method('from')->willReturnSelf();
		$queryBuilder->method('where')->willReturnSelf();
		$queryBuilder->method('executeQuery')->willReturn($result);

		return $queryBuilder;
	}//end countQueryBuilder()

	/**
	 * Build a query-builder mock that satisfies QBMapper::delete()'s DELETE statement.
	 *
	 * @return IQueryBuilder&MockObject The query builder.
	 */
	private function deleteQueryBuilder(): IQueryBuilder {
		$expression = $this->createMock(IExpressionBuilder::class);
		$expression->method('eq')->willReturn('id = :id');

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$queryBuilder->method('expr')->willReturn($expression);
		$queryBuilder->method('delete')->willReturnSelf();
		$queryBuilder->method('where')->willReturnSelf();
		$queryBuilder->method('createNamedParameter')->willReturn(':id');
		$queryBuilder->method('executeStatement')->willReturn(1);

		return $queryBuilder;
	}//end deleteQueryBuilder()

	/**
	 * REQ + SCENARIO: "Mapper guard protects non-controller callers".
	 *
	 * A schema whose magic table still holds live rows MUST NOT be deletable without
	 * an explicit force bypass — this is what makes the AI-facing SchemasToolProvider
	 * and SchemaTool surfaces safe.
	 */
	public function testDeleteRefusesWhenMagicTableHoldsObjects(): void {
		$this->db->method('tableExists')
			->with('openregister_table_7_42')
			->willReturn(true);

		$this->db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->registerListQueryBuilder(),
			$this->countQueryBuilder(3)
		);

		// The schema MUST NOT be deleted, so no deletion event may fire.
		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('Cannot delete schema: 3 objects are still attached.');

		$this->mapper->delete($this->schema);

	}//end testDeleteRefusesWhenMagicTableHoldsObjects()

	/**
	 * The force bypass — and ONLY the force bypass — permits orphaning the objects.
	 *
	 * It skips the count entirely: no register lookup, no count query, straight to the
	 * DELETE. This is the `?force=true` disposition of DELETE /api/schemas/{id}.
	 */
	public function testDeleteWithForceBypassesTheGuard(): void {
		// Force must not even ASK how many objects are attached.
		$this->db->expects($this->never())->method('tableExists');

		$this->db->expects($this->once())
			->method('getQueryBuilder')
			->willReturn($this->deleteQueryBuilder());

		$this->eventDispatcher->expects($this->once())->method('dispatchTyped');

		$deleted = $this->mapper->delete($this->schema, force: true);

		$this->assertSame($this->schema, $deleted);

	}//end testDeleteWithForceBypassesTheGuard()

	/**
	 * A schema with an EMPTY magic table deletes cleanly with no force flag — this is
	 * also the cascade's path, which deletes the rows first so the guard counts 0.
	 */
	public function testDeleteSucceedsWhenNoObjectsAreAttached(): void {
		$this->db->method('tableExists')->willReturn(true);

		$this->db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->registerListQueryBuilder(),
			$this->countQueryBuilder(0),
			$this->deleteQueryBuilder()
		);

		$this->eventDispatcher->expects($this->once())->method('dispatchTyped');

		$deleted = $this->mapper->delete($this->schema);

		$this->assertSame($this->schema, $deleted);

	}//end testDeleteSucceedsWhenNoObjectsAreAttached()

	/**
	 * A schema that never had a magic table (no rows anywhere) deletes cleanly: the
	 * count skips registers whose magic table does not exist rather than exploding on
	 * a missing table.
	 */
	public function testDeleteSkipsRegistersWithoutAMagicTable(): void {
		$this->db->method('tableExists')->willReturn(false);

		$this->db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->registerListQueryBuilder(),
			$this->deleteQueryBuilder()
		);

		$deleted = $this->mapper->delete($this->schema);

		$this->assertSame($this->schema, $deleted);

	}//end testDeleteSkipsRegistersWithoutAMagicTable()

}//end class
