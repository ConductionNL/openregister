<?php

/**
 * Characterisation tests for the native-SQL predicates AggregationRunner adds.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Aggregation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\NativeAggregationQuery;
use OCA\OpenRegister\Service\Aggregation\NativeFilterColumn;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins addDraftPredicate() for a missing status column, an anonymous caller, a
 * caller whose id is empty and a named caller, and addOperatorPredicates() for
 * every operator it knows, in map order, plus one it does not.
 */
class AggregationRunnerNativePredicateTest extends TestCase {

	/**
	 * A runner whose session holds the given user id (null: nobody signed in).
	 *
	 * @param string|null $uid           The caller's id.
	 * @param bool        $statusMissing Whether the table lacks the `_status` column.
	 *
	 * @return AggregationRunner
	 */
	private function runner(?string $uid, bool $statusMissing = false): AggregationRunner {
		$healer = $this->createMock(MagicMetadataColumnHealer::class);
		$healer->method('missingFor')->with('register_1_schema_2')->willReturn($statusMissing === true ? ['_status'] : ['_other']);
		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('getMetadataColumnHealer')->willReturn($healer);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return new AggregationRunner(
			catalog: new SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $magicMapper),
			placeholders: new PlaceholderResolver($userSession),
			db: $this->createMock(IDBConnection::class),
			cache: $this->createMock(AggregationCache::class),
			permissionHandler: $this->createMock(PermissionHandler::class),
			userSession: $userSession,
			organisationService: $this->createMock(OrganisationService::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			translationHandler: $this->createMock(TranslationHandler::class),
			languageService: $this->createMock(LanguageService::class),
		);
	}

	private function query(): NativeAggregationQuery {
		return new NativeAggregationQuery(platform: 'postgres', quote: '"', tableName: 'register_1_schema_2', metric: 'count');
	}

	/**
	 * Run addDraftPredicate() and return the WHERE clause and its bindings.
	 *
	 * @param AggregationRunner $runner The runner.
	 *
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function draftPredicate(AggregationRunner $runner): array {
		$query = $this->query();
		(new ReflectionMethod(AggregationRunner::class, 'addDraftPredicate'))->invoke($runner, $query, new Schema());

		return [$query->whereSql(), $query->bindings()];
	}

	public function testNoDraftPredicateWithoutAStatusColumn(): void {
		$this->assertSame(['', []], $this->draftPredicate($this->runner(uid: 'alice', statusMissing: true)));
	}

	public function testAnAnonymousAndAnEmptyCallerSeeNoDraftsAndBindNothing(): void {
		[$anonymous, $anonymousBindings] = $this->draftPredicate($this->runner(uid: null));
		[$empty, $emptyBindings] = $this->draftPredicate($this->runner(uid: ''));

		$this->assertNotSame('', $anonymous);
		$this->assertStringNotContainsString('?', $anonymous);
		$this->assertSame([], $anonymousBindings);
		$this->assertSame($anonymous, $empty);
		$this->assertSame([], $emptyBindings);
	}

	public function testANamedCallerAlsoSeesTheirOwnDraftsThroughOneBinding(): void {
		[$named, $bindings] = $this->draftPredicate($this->runner(uid: 'alice'));
		[$anonymous] = $this->draftPredicate($this->runner(uid: null));

		$this->assertSame('(' . $anonymous . ' OR _owner = ?)', $named);
		$this->assertSame(['alice'], $bindings);
	}

	public function testOperatorPredicatesFollowTheMapOrder(): void {
		$query = $this->query();
		$target = new NativeFilterColumn(query: $query, column: '"c"');
		(new ReflectionMethod(AggregationRunner::class, 'addOperatorPredicates'))->invoke(
			$this->runner(uid: null),
			$target,
			['isnull' => true, 'gte' => 1, 'like' => 'x', 'in' => 'not-a-list', 'lt' => 5, 'isnull ' => 1, 'ne' => 'z', 'gt' => 0, 'lte' => 9]
		);
		$query->where(predicate: 'END');
		$sql = $query->whereSql();

		$this->assertStringStartsWith('"c" IS NULL AND "c" >= ? AND ', $sql);
		$this->assertStringEndsWith(' AND "c" < ? AND "c" <> ? AND "c" > ? AND "c" <= ? AND END', $sql);
		$this->assertStringNotContainsString('like', $sql);
		$this->assertSame(['1', '5', 'z', '0', '9'], array_slice($query->bindings(), -5));
	}

	public function testAnIsnullFalseOperatorAsksForAValue(): void {
		$query = $this->query();
		(new ReflectionMethod(AggregationRunner::class, 'addOperatorPredicates'))->invoke(
			$this->runner(uid: null),
			new NativeFilterColumn(query: $query, column: '"c"'),
			['isnull' => false]
		);

		$this->assertSame('"c" IS NOT NULL', $query->whereSql());
		$this->assertSame([], $query->bindings());
	}
}//end class
