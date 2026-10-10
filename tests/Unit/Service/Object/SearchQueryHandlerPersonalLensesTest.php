<?php

/**
 * Unit tests for the `_favourite=true` and `_recent=true` lenses on object search.
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
 * @spec openspec/specs/object-interactions/spec.md#requirement-favourites-and-recent-are-lenses-on-the-object-query
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ViewScopeApplier;
use OCA\OpenRegister\Db\WatcherMapper;
use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Each lens hands the mapper a user, and never a fetched page.
 *
 * The failure guarded against here is silent in both directions. A
 * `_favourite=true` that resolves to "no restriction" answers the WHOLE
 * register while the caller reads it as "the ones I starred". And a
 * `_favourite` that survives into the mapper as an unknown key is read as a
 * filter on a property by that name, which answers `1 = 0`: an empty page with
 * no explanation. So every assertion here is about what LEAVES the query and
 * what replaces it.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Object\SearchQueryHandler
 */
class SearchQueryHandlerPersonalLensesTest extends TestCase {

	/**
	 * A handler acting as the given user.
	 *
	 * @param string|null $uid The calling uid, or null for anonymous.
	 * @param ReadHistoryService|null $readHistory The read history, or null for none.
	 *
	 * @return SearchQueryHandler
	 */
	private function makeHandler(?string $uid = 'alice', ?ReadHistoryService $readHistory = null): SearchQueryHandler {
		$schema = $this->createMock(originalClassName: Schema::class);
		$schema->method('getProperties')->willReturn([]);
		$schema->method('getObjectSource')->willReturn(null);

		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

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
			$this->createMock(originalClassName: WatcherMapper::class),
			$session,
			null,
			null,
			$readHistory
		);

	}//end makeHandler()

	/**
	 * The favourites lens resolves the caller and hands the mapper a uid.
	 *
	 * `_favouriteFor` is what the mapper turns into a correlated EXISTS. If it
	 * is absent the whole lens is a no-op and the index page answers every
	 * object, starred or not.
	 *
	 * @return void
	 */
	public function testFavouriteLensResolvesTheCaller(): void {
		$query = $this->makeHandler()->buildSearchQuery(['_favourite' => 'true'], 1, 777);

		$this->assertSame(expected: 'alice', actual: ($query['_favouriteFor'] ?? null));
		$this->assertArrayNotHasKey(key: '_favourite', array: $query);

	}//end testFavouriteLensResolvesTheCaller()

	/**
	 * A read history that answers the given lens for exactly one uid.
	 *
	 * @param string|null $uid  The uid the lens must be asked for.
	 * @param array       $lens The lens answer.
	 *
	 * @return ReadHistoryService
	 */
	private function historyAnswering(?string $uid, array $lens): ReadHistoryService {
		$history = $this->createMock(originalClassName: ReadHistoryService::class);
		$history->expects($this->once())
			->method('resolveRecentLens')
			->with($uid)
			->willReturn($lens);

		return $history;

	}//end historyAnswering()

	/**
	 * The recent lens asks the read history for the CALLER and lands on `_ids`.
	 *
	 * The wiring is asserted from the caller: buildSearchQuery() must reach
	 * ReadHistoryService::resolveRecentLens() with the session's uid, and the
	 * history must come back as the id set, its order and the lens report.
	 *
	 * @return void
	 */
	public function testRecentLensReadsTheCallersHistory(): void {
		$views = ['uuid-b' => '2026-10-09T10:00:00+00:00', 'uuid-a' => '2026-10-08T09:00:00+00:00'];
		$history = $this->historyAnswering(uid: 'alice', lens: ['available' => true, 'reason' => null, 'views' => $views]);

		$query = $this->makeHandler(readHistory: $history)->buildSearchQuery(['_recent' => 'true'], 1, 777);

		$this->assertSame(expected: ['uuid-b', 'uuid-a'], actual: $query['_ids']);
		$this->assertSame(expected: $views, actual: $query['_recentViews']);
		$this->assertSame(expected: ['available' => true, 'reason' => null], actual: $query['_recentLens']);
		$this->assertArrayNotHasKey(key: '_recent', array: $query);
		$this->assertArrayNotHasKey(key: '_recentFor', array: $query);

	}//end testRecentLensReadsTheCallersHistory()

	/**
	 * Audit trail off: an empty page that says why.
	 *
	 * @return void
	 */
	public function testRecentLensWithAuditOffIsEmptyAndSaysWhy(): void {
		$history = $this->historyAnswering(
			uid: 'alice',
			lens: ['available' => false, 'reason' => 'audit-trail-disabled', 'views' => []]
		);

		$query = $this->makeHandler(readHistory: $history)->buildSearchQuery(['_recent' => 'true'], 1, 777);

		$this->assertCount(expectedCount: 1, haystack: $query['_ids']);
		$this->assertStringStartsWith(prefix: '__', string: $query['_ids'][0]);
		$this->assertArrayNotHasKey(key: '_recentViews', array: $query);
		$this->assertSame(
			expected: ['available' => false, 'reason' => 'audit-trail-disabled'],
			actual: $query['_recentLens']
		);

	}//end testRecentLensWithAuditOffIsEmptyAndSaysWhy()

	/**
	 * An explicit `_ids` intersects with the history and keeps its order.
	 *
	 * @return void
	 */
	public function testRecentLensIntersectsAnExplicitIdSet(): void {
		$views = ['uuid-c' => '2026-10-09T11:00:00+00:00', 'uuid-b' => '2026-10-09T10:00:00+00:00'];
		$history = $this->historyAnswering(uid: 'alice', lens: ['available' => true, 'reason' => null, 'views' => $views]);

		$query = $this->makeHandler(readHistory: $history)->buildSearchQuery(
			['_recent' => 'true', '_ids' => 'uuid-a,uuid-b'],
			1,
			777
		);

		$this->assertSame(expected: ['uuid-b'], actual: $query['_ids']);
		$this->assertSame(expected: ['uuid-b' => '2026-10-09T10:00:00+00:00'], actual: $query['_recentViews']);

	}//end testRecentLensIntersectsAnExplicitIdSet()

	/**
	 * A caller cannot hand in a history: the internal keys are stripped.
	 *
	 * Without this a request could carry `_recentViews` and forge both the
	 * order and `@self.viewedAt`, or `_recentFor` and name another user.
	 *
	 * @return void
	 */
	public function testAForgedHistoryIsStripped(): void {
		$query = $this->makeHandler()->buildSearchQuery(
			['_recentViews' => ['x' => 'y'], '_recentFor' => 'bob', '_recentLens' => ['available' => true]],
			1,
			777
		);

		$this->assertArrayNotHasKey(key: '_recentViews', array: $query);
		$this->assertArrayNotHasKey(key: '_recentFor', array: $query);
		$this->assertArrayNotHasKey(key: '_recentLens', array: $query);

	}//end testAForgedHistoryIsStripped()

	/**
	 * Both lenses at once resolve to two keys, not one overwriting the other.
	 *
	 * @return void
	 */
	public function testBothLensesResolveTogether(): void {
		$history = $this->historyAnswering(
			uid: 'alice',
			lens: ['available' => true, 'reason' => null, 'views' => ['uuid-a' => '2026-10-09T10:00:00+00:00']]
		);
		$query = $this->makeHandler(readHistory: $history)->buildSearchQuery(
			['_favourite' => 'true', '_recent' => 'true'],
			1,
			777
		);

		$this->assertSame(expected: 'alice', actual: ($query['_favouriteFor'] ?? null));
		$this->assertSame(expected: ['uuid-a'], actual: ($query['_ids'] ?? null));

	}//end testBothLensesResolveTogether()

	/**
	 * A lens composes with every other filter rather than replacing it.
	 *
	 * This is the scenario the delta names: a favourites chip on an index page
	 * that is already filtered to open cases answers the starred OPEN ones.
	 *
	 * @return void
	 */
	public function testTheLensLeavesOtherFiltersAlone(): void {
		$query = $this->makeHandler()->buildSearchQuery(
			['_favourite' => 'true', 'status' => 'open'],
			1,
			777
		);

		$this->assertSame(expected: 'alice', actual: ($query['_favouriteFor'] ?? null));
		$this->assertSame(expected: 'open', actual: ($query['status'] ?? null));

	}//end testTheLensLeavesOtherFiltersAlone()

	/**
	 * An anonymous caller gets an empty page, never the whole register.
	 *
	 * The guard is an id literal no object can carry. Asserting that
	 * `_favouriteFor` is ABSENT as well is the half that matters: without it the
	 * mapper would see no restriction at all and the `_ids` guard would be the
	 * only thing standing between anonymous and every row.
	 *
	 * @return void
	 */
	public function testAnonymousGetsAnEmptyPage(): void {
		$query = $this->makeHandler(uid: null)->buildSearchQuery(['_favourite' => 'true'], 1, 777);

		$this->assertArrayHasKey(key: '_ids', array: $query);
		$this->assertNotEmpty(actual: $query['_ids']);
		$this->assertArrayNotHasKey(key: '_favouriteFor', array: $query);

	}//end testAnonymousGetsAnEmptyPage()

	/**
	 * An anonymous recent query is guarded the same way.
	 *
	 * @return void
	 */
	public function testAnonymousRecentGetsAnEmptyPage(): void {
		$history = $this->historyAnswering(
			uid: null,
			lens: ['available' => false, 'reason' => 'anonymous', 'views' => []]
		);
		$query = $this->makeHandler(uid: null, readHistory: $history)->buildSearchQuery(['_recent' => 'true'], 1, 777);

		$this->assertArrayHasKey(key: '_ids', array: $query);
		$this->assertNotEmpty(actual: $query['_ids']);
		$this->assertArrayNotHasKey(key: '_recentViews', array: $query);
		$this->assertSame(expected: 'anonymous', actual: $query['_recentLens']['reason']);

	}//end testAnonymousRecentGetsAnEmptyPage()

	/**
	 * `_favourite=false` turns the lens off and removes the key.
	 *
	 * Both halves matter. Leaving the lens on would answer the wrong set, and
	 * leaving the key behind would reach the mapper as a filter on a property
	 * nothing has, which is an empty page.
	 *
	 * @return void
	 */
	public function testFalseTurnsTheLensOff(): void {
		$query = $this->makeHandler()->buildSearchQuery(['_favourite' => 'false'], 1, 777);

		$this->assertArrayNotHasKey(key: '_favouriteFor', array: $query);
		$this->assertArrayNotHasKey(key: '_favourite', array: $query);

	}//end testFalseTurnsTheLensOff()

	/**
	 * `_recent=false` turns its lens off the same way.
	 *
	 * @return void
	 */
	public function testFalseTurnsTheRecentLensOff(): void {
		$query = $this->makeHandler()->buildSearchQuery(['_recent' => 'false'], 1, 777);

		$this->assertArrayNotHasKey(key: '_recentViews', array: $query);
		$this->assertArrayNotHasKey(key: '_recentLens', array: $query);
		$this->assertArrayNotHasKey(key: '_recent', array: $query);

	}//end testFalseTurnsTheRecentLensOff()

	/**
	 * A query naming neither lens is untouched by them.
	 *
	 * The control: it separates "the lens did its job" from "buildSearchQuery
	 * writes these keys for everyone", which would make every assertion above
	 * pass for the wrong reason.
	 *
	 * @return void
	 */
	public function testAQueryWithoutALensIsUntouched(): void {
		$query = $this->makeHandler()->buildSearchQuery(['status' => 'open'], 1, 777);

		$this->assertArrayNotHasKey(key: '_favouriteFor', array: $query);
		$this->assertArrayNotHasKey(key: '_recentViews', array: $query);
		$this->assertArrayNotHasKey(key: '_recentLens', array: $query);

	}//end testAQueryWithoutALensIsUntouched()
}//end class
