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
 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-favourites-and-recent-are-lenses-on-the-object-query
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ViewScopeApplier;
use OCA\OpenRegister\Db\WatcherMapper;
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
	 *
	 * @return SearchQueryHandler
	 */
	private function makeHandler(?string $uid = 'alice'): SearchQueryHandler {
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
			$session
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
	 * The recent lens resolves the caller into its own key.
	 *
	 * @return void
	 */
	public function testRecentLensResolvesTheCaller(): void {
		$query = $this->makeHandler()->buildSearchQuery(['_recent' => 'true'], 1, 777);

		$this->assertSame(expected: 'alice', actual: ($query['_recentFor'] ?? null));
		$this->assertArrayNotHasKey(key: '_recent', array: $query);

	}//end testRecentLensResolvesTheCaller()

	/**
	 * Both lenses at once resolve to two keys, not one overwriting the other.
	 *
	 * @return void
	 */
	public function testBothLensesResolveTogether(): void {
		$query = $this->makeHandler()->buildSearchQuery(
			['_favourite' => 'true', '_recent' => 'true'],
			1,
			777
		);

		$this->assertSame(expected: 'alice', actual: ($query['_favouriteFor'] ?? null));
		$this->assertSame(expected: 'alice', actual: ($query['_recentFor'] ?? null));

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
		$query = $this->makeHandler(uid: null)->buildSearchQuery(['_recent' => 'true'], 1, 777);

		$this->assertArrayHasKey(key: '_ids', array: $query);
		$this->assertNotEmpty(actual: $query['_ids']);
		$this->assertArrayNotHasKey(key: '_recentFor', array: $query);

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

		$this->assertArrayNotHasKey(key: '_recentFor', array: $query);
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
		$this->assertArrayNotHasKey(key: '_recentFor', array: $query);

	}//end testAQueryWithoutALensIsUntouched()
}//end class
