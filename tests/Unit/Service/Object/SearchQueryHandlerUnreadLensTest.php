<?php

/**
 * Unit tests for the `_unread=true` lens on object search.
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
 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
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
 * The lens hands the mapper a reader, and never a fetched page.
 *
 * The failure this guards against has two shapes, and both are silent. An
 * `_unread=true` that resolves to "no restriction" returns the WHOLE register
 * while the caller reads it as "what moved overnight". And an `_unread` that
 * survives into the mapper as an unknown key is read as a filter on a property
 * called `_unread`, which answers `1 = 0`: an empty page with no explanation.
 * So the assertions here are about what leaves the query and what replaces it.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Object\SearchQueryHandler
 */
class SearchQueryHandlerUnreadLensTest extends TestCase {

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
	 * The lens resolves the reader and hands the mapper a uid.
	 *
	 * `_unreadFor` is what the mapper turns into a correlated NOT EXISTS. If it
	 * is absent, the whole lens is a no-op and the list silently answers every
	 * object, read or not.
	 *
	 * @return void
	 */
	public function testTheLensResolvesTheCallerForTheMapper(): void {
		$query = $this->makeHandler()->buildSearchQuery(['_unread' => 'true'], 1, 777);

		$this->assertSame(expected: 'alice', actual: ($query['_unreadFor'] ?? null));
		$this->assertArrayNotHasKey(key: '_unread', array: $query);

	}//end testTheLensResolvesTheCallerForTheMapper()

	/**
	 * The lens never turns into a fetched id set.
	 *
	 * A page of ids would be a post-filter wearing a lens's clothes: the total
	 * would count rows the page then dropped, which is the paging failure the
	 * requirement forbids by name.
	 *
	 * @return void
	 */
	public function testTheLensDoesNotMaterialiseAnIdSet(): void {
		$query = $this->makeHandler()->buildSearchQuery(['_unread' => 'true'], 1, 777);

		$this->assertArrayNotHasKey(key: '_ids', array: $query);

	}//end testTheLensDoesNotMaterialiseAnIdSet()

	/**
	 * An anonymous caller gets an impossible id set, never the whole register.
	 *
	 * @return void
	 */
	public function testAnonymousCallerGetsAnImpossibleIdSet(): void {
		$query = $this->makeHandler(uid: null)->buildSearchQuery(['_unread' => 'true'], 1, 777);

		$ids = ($query['_ids'] ?? null);
		$this->assertIsArray(actual: $ids);
		$this->assertCount(expectedCount: 1, haystack: $ids);
		$this->assertStringContainsString(needle: 'no-unread-reader', haystack: $ids[0]);
		$this->assertArrayNotHasKey(key: '_unreadFor', array: $query);

	}//end testAnonymousCallerGetsAnImpossibleIdSet()

	/**
	 * `_unread=false` asks for no restriction, and gets none.
	 *
	 * @return void
	 */
	public function testAskingForUnreadFalseRestrictsNothing(): void {
		$query = $this->makeHandler()->buildSearchQuery(['_unread' => 'false'], 1, 777);

		$this->assertArrayNotHasKey(key: '_unreadFor', array: $query);
		$this->assertArrayNotHasKey(key: '_unread', array: $query);
		$this->assertArrayNotHasKey(key: '_ids', array: $query);

	}//end testAskingForUnreadFalseRestrictsNothing()

	/**
	 * `_unread` is a reserved parameter, so it can never be read as a filter.
	 *
	 * The lens strips it, and this asserts the second line of defence: even
	 * spelled straight at the mapper it is context, not a property called
	 * `_unread` that answers `1 = 0`.
	 *
	 * @return void
	 */
	public function testTheLensParametersAreReserved(): void {
		// Read through reflection because the list is private: the point is to
		// assert the CONTENTS of the one list the mapper actually consults, not
		// to widen its visibility for a test's convenience.
		$method = new \ReflectionMethod(MagicSearchHandler::class, 'getReservedParams');
		$method->setAccessible(true);
		$reserved = $method->invoke(
			$this->createMock(originalClassName: MagicSearchHandler::class)
		);

		$this->assertContains(needle: '_unread', haystack: $reserved);
		$this->assertContains(needle: '_unreadFor', haystack: $reserved);

	}//end testTheLensParametersAreReserved()
}//end class
