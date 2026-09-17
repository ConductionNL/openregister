<?php

/**
 * OpenRegister - the archive lens on the query.
 *
 * Pins the three answers `_archived` can give and, more importantly, the one
 * it gives when nobody asked: the working set.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
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
 */
final class MagicSearchHandlerArchiveLensTest extends TestCase {

	/**
	 * A handler with no constructor run.
	 *
	 * `resolveArchivedMode()` reads nothing but its argument, so wiring the
	 * handler's full collaborator graph to ask it a question about an array
	 * would test the container rather than the lens.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function handler(): MagicSearchHandler {
		return (new ReflectionClass(objectOrClass: MagicSearchHandler::class))
			->newInstanceWithoutConstructor();
	}//end handler()

	/**
	 * A query with no `_archived` parameter answers the working set.
	 *
	 * This is the one that matters. Exclusion is a default, not a filter the
	 * caller has to remember, because a caller who forgets must get the safe
	 * answer rather than the archive.
	 *
	 * @return void
	 */
	public function testNoParameterMeansTheWorkingSet(): void {
		$this->assertSame(
			MagicSearchHandler::ARCHIVED_EXCLUDE,
			$this->handler()->resolveArchivedMode(query: ['_limit' => 20])
		);
	}//end testNoParameterMeansTheWorkingSet()

	/**
	 * `_archived=true` is the archived lens alone.
	 *
	 * @param mixed $value A spelling of true that arrives over the wire.
	 *
	 * @return void
	 *
	 * @dataProvider trueSpellings
	 */
	public function testTrueMeansTheArchivedLensAlone(mixed $value): void {
		$this->assertSame(
			MagicSearchHandler::ARCHIVED_ONLY,
			$this->handler()->resolveArchivedMode(query: ['_archived' => $value])
		);
	}//end testTrueMeansTheArchivedLensAlone()

	/**
	 * The spellings of true a query string actually produces.
	 *
	 * @return array<string, array{0: mixed}> The cases.
	 */
	public static function trueSpellings(): array {
		return [
			'the string' => ['true'],
			'the boolean' => [true],
			'the digit' => ['1'],
		];
	}//end trueSpellings()

	/**
	 * `_archived=any` shows both.
	 *
	 * ⚠️ The case a boolean coercion cannot see. `filter_var("any",
	 * FILTER_VALIDATE_BOOLEAN)` is false, which is the same answer it gives
	 * `_archived=false` — so the one value that means "show me everything"
	 * would have silently meant "hide the archive".
	 *
	 * @return void
	 */
	public function testAnyShowsBoth(): void {
		$this->assertSame(
			MagicSearchHandler::ARCHIVED_ANY,
			$this->handler()->resolveArchivedMode(query: ['_archived' => 'any'])
		);
	}//end testAnyShowsBoth()

	/**
	 * `_archived=ANY` is the same lens: a caller should not have to know the
	 * casing.
	 *
	 * @return void
	 */
	public function testAnyIsCaseInsensitive(): void {
		$this->assertSame(
			MagicSearchHandler::ARCHIVED_ANY,
			$this->handler()->resolveArchivedMode(query: ['_archived' => 'ANY'])
		);
	}//end testAnyIsCaseInsensitive()

	/**
	 * An unrecognised value falls back to the working set.
	 *
	 * An unknown lens must never widen what a list shows, because the way a
	 * typo would then fail is by publishing archived records.
	 *
	 * @param mixed $value The unrecognised value.
	 *
	 * @return void
	 *
	 * @dataProvider unrecognisedValues
	 */
	public function testAnUnrecognisedValueFallsBackToTheWorkingSet(mixed $value): void {
		$this->assertSame(
			MagicSearchHandler::ARCHIVED_EXCLUDE,
			$this->handler()->resolveArchivedMode(query: ['_archived' => $value])
		);
	}//end testAnUnrecognisedValueFallsBackToTheWorkingSet()

	/**
	 * Values that are neither a true nor `any`.
	 *
	 * @return array<string, array{0: mixed}> The cases.
	 */
	public static function unrecognisedValues(): array {
		return [
			'explicit false' => ['false'],
			'a typo' => ['al'],
			'the empty string' => [''],
			'null' => [null],
		];
	}//end unrecognisedValues()

	/**
	 * `_archived` is a reserved parameter, so it is never read as a filter on
	 * a schema property called "archived".
	 *
	 * A context parameter mistaken for a property filter emits `1 = 0` and
	 * returns nothing, with no error — the failure the comment on
	 * `getReservedParams()` records.
	 *
	 * @return void
	 */
	public function testArchivedIsAReservedParameter(): void {
		$method = (new ReflectionClass(objectOrClass: MagicSearchHandler::class))
			->getMethod('getReservedParams');
		$method->setAccessible(true);

		$reserved = $method->invoke($this->handler());

		$this->assertContains('_archived', $reserved);
	}//end testArchivedIsAReservedParameter()

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

		// RBAC waved through: this test is about the archive condition, and an
		// RBAC double that answers an empty array makes the production code
		// read a key that is not there — a warning about the DOUBLE, mistakable
		// for one about the subject.
		$rbac = $this->createMock(originalClassName: MagicRbacHandler::class);
		$rbac->method('buildRbacConditionsSql')->willReturn(['bypass' => true, 'conditions' => []]);

		return new MagicSearchHandler(
			$db,
			$this->createMock(originalClassName: LoggerInterface::class),
			$rbac,
			$this->createMock(originalClassName: MagicOrganizationHandler::class),
			$this->createMock(originalClassName: SchemaTypeConverter::class),
			$this->createMock(originalClassName: DateTimeNormalizer::class)
		);
	}//end handlerWithDb()

	/**
	 * The raw SQL path carries the exclusion too.
	 *
	 * ⚠️ NOT a duplicate of the resolver tests. The resolver answers what the
	 * caller asked for; this answers whether the answer reaches SQL. The two
	 * query paths in this class build the same WHERE by different means, and
	 * the comment on step 3 of `buildWhereConditionsSql()` records what
	 * happened the last time only one of them was changed: the UNION path
	 * silently returned MORE rows for the same query. Here that would be an
	 * archived record in a working list.
	 *
	 * @return void
	 */
	public function testTheUnionPathExcludesArchivedByDefault(): void {
		$conditions = $this->handlerWithDb()->buildWhereConditionsSql(
			query: [],
			schema: new Schema()
		);

		$this->assertContains('_archived IS NULL', $conditions);
	}//end testTheUnionPathExcludesArchivedByDefault()

	/**
	 * `_archived=true` flips the same path to the archived lens.
	 *
	 * @return void
	 */
	public function testTheUnionPathCanAskForArchivedOnly(): void {
		$conditions = $this->handlerWithDb()->buildWhereConditionsSql(
			query: ['_archived' => 'true'],
			schema: new Schema()
		);

		$this->assertContains('_archived IS NOT NULL', $conditions);
		$this->assertNotContains('_archived IS NULL', $conditions);
	}//end testTheUnionPathCanAskForArchivedOnly()

	/**
	 * `_archived=any` puts no archive condition on the query at all.
	 *
	 * The control for the two tests above: a path that always emitted a
	 * condition would pass both of them and still be wrong.
	 *
	 * @return void
	 */
	public function testTheUnionPathAsksForNothingWhenBothAreWanted(): void {
		$conditions = $this->handlerWithDb()->buildWhereConditionsSql(
			query: ['_archived' => 'any'],
			schema: new Schema()
		);

		$this->assertNotContains('_archived IS NULL', $conditions);
		$this->assertNotContains('_archived IS NOT NULL', $conditions);
	}//end testTheUnionPathAsksForNothingWhenBothAreWanted()
}//end class
