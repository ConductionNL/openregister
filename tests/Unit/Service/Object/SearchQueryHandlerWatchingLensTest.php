<?php

/**
 * Unit tests for the `_watching=true` lens on object search.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ViewScopeApplier;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The lens narrows the query to the caller's follows, in the query.
 *
 * The failure this guards against is the one that makes a lens useless and
 * dangerous at the same time: a `_watching=true` that resolves to "no
 * restriction" returns the WHOLE register while the caller reads it as "the
 * cases I follow". So every assertion here is about what is EXCLUDED.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Object\SearchQueryHandler
 */
class SearchQueryHandlerWatchingLensTest extends TestCase {

	/**
	 * A handler for the given caller.
	 *
	 * @param array<int, string> $watched Unused since the lens became an EXISTS; kept for the call sites.
	 * @param string|null $uid The calling uid, or null for anonymous.
	 *
	 * @return SearchQueryHandler
	 */
	private function makeHandler(array $watched, ?string $uid = 'alice'): SearchQueryHandler {
		$schema = $this->createMock(originalClassName: Schema::class);
		$schema->method('getProperties')->willReturn([]);
		$schema->method('getObjectSource')->willReturn(null);

		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		// The lens no longer reads the follows itself: it is an EXISTS in the
		// mapper's query, so the list of followed uuids plays no part here.
		unset($watched);

		$session = $this->createMock(originalClassName: IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new SearchQueryHandler(
			$this->createMock(originalClassName: ViewScopeApplier::class),
			$schemaMapper,
			$this->createMock(originalClassName: SettingsService::class),
			$this->createMock(originalClassName: LoggerInterface::class),
			$this->createMock(originalClassName: IRequest::class),
			$this->createMock(originalClassName: SearchTrailService::class),
			$session
		);
	}//end makeHandler()

	/**
	 * The lens is resolved in the query: the caller's uid, and no uuid list.
	 *
	 * @return void
	 */
	public function testTheLensIsResolvedInTheQuery(): void {
		$query = $this->makeHandler(watched: ['uuid-a', 'uuid-b'])->buildSearchQuery(
			['_watching' => 'true'],
			1,
			777
		);

		$this->assertSame(expected: 'alice', actual: ($query['_watchingFor'] ?? null));
		$this->assertArrayNotHasKey(key: '_ids', array: $query);
		$this->assertArrayNotHasKey(key: '_watching', array: $query);
	}//end testTheLensIsResolvedInTheQuery()

	/**
	 * The deprecated `_favourite=true` asks the same question.
	 *
	 * @return void
	 */
	public function testTheFavouriteFlagIsAnAliasOfTheLens(): void {
		$query = $this->makeHandler(watched: [])->buildSearchQuery(['_favourite' => 'true'], 1, 777);

		$this->assertSame(expected: 'alice', actual: ($query['_watchingFor'] ?? null));
		$this->assertArrayNotHasKey(key: '_favourite', array: $query);
		$this->assertArrayNotHasKey(key: '_favouriteFor', array: $query);
	}//end testTheFavouriteFlagIsAnAliasOfTheLens()

	/**
	 * A caller cannot hand in another user's uid as the resolved key.
	 *
	 * @return void
	 */
	public function testAForgedResolvedKeyIsDropped(): void {
		$query = $this->makeHandler(watched: [])->buildSearchQuery(['_watchingFor' => 'bob'], 1, 777);

		$this->assertArrayNotHasKey(key: '_watchingFor', array: $query);
	}//end testAForgedResolvedKeyIsDropped()

	/**
	 * An anonymous caller follows nothing, and is told nothing.
	 *
	 * @return void
	 */
	public function testAnonymousCallerGetsAnImpossibleIdSet(): void {
		$query = $this->makeHandler(watched: ['uuid-a'], uid: null)->buildSearchQuery(
			['_watching' => 'true'],
			1,
			777
		);

		$ids = ($query['_ids'] ?? null);
		$this->assertIsArray(actual: $ids);
		$this->assertCount(expectedCount: 1, haystack: $ids);
		$this->assertStringContainsString(needle: 'no-watched-objects', haystack: $ids[0]);
		$this->assertArrayNotHasKey(key: '_watchingFor', array: $query);
	}//end testAnonymousCallerGetsAnImpossibleIdSet()

	/**
	 * An explicit id set is kept, and the lens composes with it in the query.
	 *
	 * @return void
	 */
	public function testAnExplicitIdSetIsKeptBesideTheLens(): void {
		$query = $this->makeHandler(watched: ['uuid-a', 'uuid-b'])->buildSearchQuery(
			['_watching' => 'true', '_ids' => 'uuid-b,uuid-c'],
			1,
			777
		);

		$this->assertSame(expected: ['uuid-b', 'uuid-c'], actual: ($query['_ids'] ?? null));
		$this->assertSame(expected: 'alice', actual: ($query['_watchingFor'] ?? null));
	}//end testAnExplicitIdSetIsKeptBesideTheLens()

	/**
	 * `_watching=false` is not a lens, and must not restrict anything.
	 *
	 * @return void
	 */
	public function testTheLensIsOffWhenNotAskedFor(): void {
		$query = $this->makeHandler(watched: ['uuid-a'])->buildSearchQuery(
			['_watching' => 'false'],
			1,
			777
		);

		$this->assertArrayNotHasKey(key: '_ids', array: $query);
		$this->assertArrayNotHasKey(key: '_watching', array: $query);
	}//end testTheLensIsOffWhenNotAskedFor()

	/**
	 * A query that never mentions the lens is untouched by it.
	 *
	 * @return void
	 */
	public function testAQueryWithoutTheLensIsUnchanged(): void {
		$query = $this->makeHandler(watched: ['uuid-a'])->buildSearchQuery(['_limit' => '10'], 1, 777);

		$this->assertArrayNotHasKey(key: '_ids', array: $query);
	}//end testAQueryWithoutTheLensIsUnchanged()
}//end class
