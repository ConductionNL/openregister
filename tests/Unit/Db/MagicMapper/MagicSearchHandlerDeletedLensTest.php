<?php

/**
 * OpenRegister - the deleted lens on the query.
 *
 * Pins the three answers `_deleted` can give, the legacy `_includeDeleted`
 * spelling, and that both query paths carry the answer to SQL.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-deleted-lens/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\DeletedLens;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @covers \OCA\OpenRegister\Db\MagicMapper\DeletedLens
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\MagicMapper\NestedPathFilter
 * @uses \OCA\OpenRegister\Service\Object\DraftListLens
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 */
final class MagicSearchHandlerDeletedLensTest extends TestCase {

	/**
	 * Which mode a query asks for, by its parameters.
	 *
	 * @param array<string, mixed> $query The query.
	 * @param string $expected The mode.
	 *
	 * @return void
	 *
	 * @dataProvider modes
	 */
	public function testTheQueryNamesItsMode(array $query, string $expected): void {
		$this->assertSame($expected, (new DeletedLens())->mode(query: $query));
	}//end testTheQueryNamesItsMode()

	/**
	 * The parameters a caller sends, and the lens each means.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}> The cases.
	 */
	public static function modes(): array {
		return [
			'nobody asked' => [['_limit' => 20], DeletedLens::EXCLUDE],
			'true as a string' => [['_deleted' => 'true'], DeletedLens::ONLY],
			'true as a boolean' => [['_deleted' => true], DeletedLens::ONLY],
			'true as a digit' => [['_deleted' => '1'], DeletedLens::ONLY],
			'any' => [['_deleted' => 'any'], DeletedLens::ANY],
			'ANY' => [['_deleted' => 'ANY'], DeletedLens::ANY],
			'explicit false' => [['_deleted' => 'false'], DeletedLens::EXCLUDE],
			'a typo' => [['_deleted' => 'yes please'], DeletedLens::EXCLUDE],
			'empty' => [['_deleted' => ''], DeletedLens::EXCLUDE],
			'the older spelling of both' => [['_includeDeleted' => 'true'], DeletedLens::ANY],
			'the older spelling, false' => [['_includeDeleted' => 'false'], DeletedLens::EXCLUDE],
			'the lens wins over the older spelling' => [['_deleted' => 'true', '_includeDeleted' => 'true'], DeletedLens::ONLY],
		];
	}//end modes()

	/**
	 * `_deleted` is reserved, so it is never read as a filter on a schema
	 * property called "deleted" (which would answer `1 = 0` and say nothing).
	 *
	 * @return void
	 */
	public function testDeletedIsAReservedParameter(): void {
		$handler = (new ReflectionClass(objectOrClass: MagicSearchHandler::class))->newInstanceWithoutConstructor();
		$method = (new ReflectionClass(objectOrClass: MagicSearchHandler::class))->getMethod('getReservedParams');
		$method->setAccessible(true);

		$this->assertContains('_deleted', $method->invoke($handler));
	}//end testDeletedIsAReservedParameter()

	/**
	 * A handler wired over a fake database, enough to build a WHERE clause.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function handlerWithDb(): MagicSearchHandler {
		$queryBuilder = $this->createMock(originalClassName: IQueryBuilder::class);
		$queryBuilder->method('getConnection')->willReturn(
			$this->createMock(originalClassName: IDBConnection::class)
		);

		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);
		$db->method('getDatabasePlatform')->willReturn(new MySQLPlatform());

		// RBAC waved through: this test is about the deleted condition, and an
		// RBAC double that answers an empty array makes the production code
		// read a key that is not there — a warning about the DOUBLE, mistakable
		// for one about the subject.
		$rbac = $this->createMock(originalClassName: MagicRbacHandler::class);
		$rbac->method('buildRbacConditionsSql')->willReturn(['bypass' => true, 'conditions' => []]);

		// Tenancy waved through for the same reason RBAC is: this test is about
		// the deleted condition. An organisation double that answers nothing
		// makes the renderer fail closed and add `1 = 0`, which is correct
		// behaviour and irrelevant noise here.
		$organisation = $this->createMock(originalClassName: MagicOrganizationHandler::class);
		$organisation->method('resolveOrganizationScope')->willReturn(
			['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => []]
		);

		return new MagicSearchHandler(
			$db,
			$this->createMock(originalClassName: LoggerInterface::class),
			$rbac,
			$organisation,
			$this->createMock(originalClassName: SchemaTypeConverter::class),
			$this->createMock(originalClassName: DateTimeNormalizer::class),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class)
		);
	}//end handlerWithDb()

	/**
	 * The UNION path: the working set by default, the deleted rows alone on
	 * `true`, and no condition on `any` (the control: a path that always
	 * emitted a condition would pass the first two).
	 *
	 * @return void
	 */
	public function testTheUnionPathCarriesTheLens(): void {
		$handler = $this->handlerWithDb();

		$working = $handler->buildWhereConditionsSql(query: [], schema: new Schema());
		$only = $handler->buildWhereConditionsSql(query: ['_deleted' => 'true'], schema: new Schema());
		$both = $handler->buildWhereConditionsSql(query: ['_deleted' => 'any'], schema: new Schema());

		$this->assertContains('_deleted IS NULL', $working);
		$this->assertContains('_deleted IS NOT NULL', $only);
		$this->assertNotContains('_deleted IS NULL', $only);
		$this->assertNotContains('_deleted IS NULL', $both);
		$this->assertNotContains('_deleted IS NOT NULL', $both);
	}//end testTheUnionPathCarriesTheLens()

	/**
	 * The single-table path spells the same predicate, on its alias.
	 *
	 * @return void
	 */
	public function testTheSingleTablePathCarriesTheLens(): void {
		$this->assertSame('t._deleted IS NULL', (new DeletedLens())->condition(mode: DeletedLens::EXCLUDE, columnPrefix: 't.'));
		$this->assertSame('t._deleted IS NOT NULL', (new DeletedLens())->condition(mode: DeletedLens::ONLY, columnPrefix: 't.'));
		$this->assertNull((new DeletedLens())->condition(mode: DeletedLens::ANY, columnPrefix: 't.'));

		$added = [];
		$qb = $this->createMock(originalClassName: IQueryBuilder::class);
		$qb->method('createFunction')->willReturnCallback(static fn (string $sql): string => $sql);
		$qb->method('andWhere')->willReturnCallback(
			function (...$where) use (&$added, $qb) {
				$added = array_merge($added, $where);
				return $qb;
			}
		);
		$method = (new ReflectionClass(objectOrClass: MagicSearchHandler::class))->getMethod('applyBasicFilters');
		$method->setAccessible(true);
		$method->invoke($this->handlerWithDb(), $qb, DeletedLens::ONLY, MagicSearchHandler::ARCHIVED_ANY, [], null, []);

		$this->assertContains('t._deleted IS NOT NULL', $added);
		$this->assertNotContains('t._deleted IS NULL', $added);
	}//end testTheSingleTablePathCarriesTheLens()

	/**
	 * A deleted row hydrates with its JSON marker, so the list that asked for
	 * it returns it; a bare timestamp from before the column was JSON still
	 * reads.
	 *
	 * @return void
	 */
	public function testADeletedRowKeepsItsMarker(): void {
		$method = (new ReflectionClass(objectOrClass: MagicSearchHandler::class))->getMethod('deletedMarker');
		$method->setAccessible(true);
		$handler = (new ReflectionClass(objectOrClass: MagicSearchHandler::class))->newInstanceWithoutConstructor();

		$json = '{"deletedAt": "2026-10-14T18:29:41+00:00", "deletedBy": "system", "purgeDate": "2028-10-13T18:29:41+00:00"}';
		$marker = $method->invoke($handler, $json);
		$legacy = $method->invoke($handler, '2026-10-14 18:29:41');

		$this->assertSame('system', $marker['deletedBy']);
		$this->assertSame('2028-10-13T18:29:41+00:00', $marker['purgeDate']);
		$this->assertStringStartsWith('2026-10-14T18:29:41', $legacy['deleted']);
	}//end testADeletedRowKeepsItsMarker()

	/**
	 * Consumers detect the lens by `MagicSearchHandler::DELETED_ONLY`.
	 *
	 * @return void
	 */
	public function testTheLensIsDiscoverableOnTheSearchHandler(): void {
		$this->assertTrue(defined('OCA\\OpenRegister\\Db\\MagicMapper\\MagicSearchHandler::DELETED_ONLY'));
		$this->assertSame(DeletedLens::ONLY, MagicSearchHandler::DELETED_ONLY);
	}//end testTheLensIsDiscoverableOnTheSearchHandler()
}//end class
