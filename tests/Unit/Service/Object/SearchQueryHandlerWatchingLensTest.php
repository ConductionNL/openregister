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
 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
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
 * The lens narrows the id set to the caller's subscriptions.
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
	 * A handler whose watcher mapper answers with the given uuids.
	 *
	 * @param array<int, string> $watched The uuids the caller follows.
	 * @param string|null $uid The calling uid, or null for anonymous.
	 *
	 * @return SearchQueryHandler
	 */
	private function makeHandler(array $watched, ?string $uid = 'alice'): SearchQueryHandler {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn([]);
		$schema->method('getObjectSource')->willReturn(null);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$watcherMapper = $this->createMock(WatcherMapper::class);
		$watcherMapper->method('uuidsForUser')->willReturn($watched);

		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new SearchQueryHandler(
			$this->createMock(ViewMapper::class),
			$schemaMapper,
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IRequest::class),
			$this->createMock(SearchTrailService::class),
			$watcherMapper,
			$session
		);
	}//end makeHandler()

	/**
	 * The lens restricts the query to the caller's subscriptions.
	 *
	 * @return void
	 */
	public function testTheLensNarrowsToTheWatchedUuids(): void {
		$query = $this->makeHandler(['uuid-a', 'uuid-b'])->buildSearchQuery(
			['_watching' => 'true'],
			1,
			777
		);

		$this->assertSame(['uuid-a', 'uuid-b'], ($query['_ids'] ?? null));
		$this->assertArrayNotHasKey('_watching', $query);
	}//end testTheLensNarrowsToTheWatchedUuids()

	/**
	 * Following nothing answers nothing, never everything.
	 *
	 * @return void
	 */
	public function testFollowingNothingReturnsAnImpossibleIdSet(): void {
		$query = $this->makeHandler([])->buildSearchQuery(['_watching' => 'true'], 1, 777);

		$ids = ($query['_ids'] ?? null);
		$this->assertIsArray($ids);
		$this->assertCount(1, $ids);
		$this->assertStringContainsString('no-watched-objects', $ids[0]);
	}//end testFollowingNothingReturnsAnImpossibleIdSet()

	/**
	 * An anonymous caller follows nothing, and is told nothing.
	 *
	 * @return void
	 */
	public function testAnonymousCallerGetsAnImpossibleIdSet(): void {
		$query = $this->makeHandler(['uuid-a'], null)->buildSearchQuery(
			['_watching' => 'true'],
			1,
			777
		);

		$ids = ($query['_ids'] ?? null);
		$this->assertIsArray($ids);
		$this->assertCount(1, $ids);
		$this->assertStringContainsString('no-watched-objects', $ids[0]);
	}//end testAnonymousCallerGetsAnImpossibleIdSet()

	/**
	 * Combining the lens with explicit ids intersects rather than replaces.
	 *
	 * @return void
	 */
	public function testTheLensIntersectsWithAnExplicitIdSet(): void {
		$query = $this->makeHandler(['uuid-a', 'uuid-b'])->buildSearchQuery(
			['_watching' => 'true', '_ids' => 'uuid-b,uuid-c'],
			1,
			777
		);

		$this->assertSame(['uuid-b'], ($query['_ids'] ?? null));
	}//end testTheLensIntersectsWithAnExplicitIdSet()

	/**
	 * `_watching=false` is not a lens, and must not restrict anything.
	 *
	 * @return void
	 */
	public function testTheLensIsOffWhenNotAskedFor(): void {
		$query = $this->makeHandler(['uuid-a'])->buildSearchQuery(
			['_watching' => 'false'],
			1,
			777
		);

		$this->assertArrayNotHasKey('_ids', $query);
		$this->assertArrayNotHasKey('_watching', $query);
	}//end testTheLensIsOffWhenNotAskedFor()

	/**
	 * A query that never mentions the lens is untouched by it.
	 *
	 * @return void
	 */
	public function testAQueryWithoutTheLensIsUnchanged(): void {
		$query = $this->makeHandler(['uuid-a'])->buildSearchQuery(['_limit' => '10'], 1, 777);

		$this->assertArrayNotHasKey('_ids', $query);
	}//end testAQueryWithoutTheLensIsUnchanged()
}//end class
