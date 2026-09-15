<?php

/**
 * Unit tests for FavouritePruneListener.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Listener\FavouritePruneListener;
use OCA\OpenRegister\Service\Interaction\FavouriteService;
use OCA\OpenRegister\Service\Interaction\ViewHistoryService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The cascade a foreign key cannot express.
 *
 * Objects live in per-schema tables, so nothing in the database removes these
 * rows on its own. If this listener is not registered, or clears only one of
 * the two tables, the leftovers are invisible: no error, no failing read, just
 * stars and views pointing at an object nobody can open.
 *
 * @coversDefaultClass \OCA\OpenRegister\Listener\FavouritePruneListener
 */
class FavouritePruneListenerTest extends TestCase {

	/**
	 * The favourites double.
	 *
	 * @var FavouriteService&MockObject
	 */
	private $favourites;

	/**
	 * The view-history double.
	 *
	 * @var ViewHistoryService&MockObject
	 */
	private $views;

	/**
	 * The listener under test.
	 *
	 * @var FavouritePruneListener
	 */
	private FavouritePruneListener $listener;

	/**
	 * Build fresh doubles for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->favourites = $this->createMock(originalClassName: FavouriteService::class);
		$this->views = $this->createMock(originalClassName: ViewHistoryService::class);
		$this->listener = new FavouritePruneListener(
			$this->favourites,
			$this->views,
			$this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * A deletion event carrying an object with the given uuid.
	 *
	 * @param string $uuid The deleted object's uuid.
	 *
	 * @return ObjectDeletedEvent
	 */
	private function deletionOf(string $uuid): ObjectDeletedEvent {
		$object = $this->createMock(originalClassName: ObjectEntity::class);
		$object->method('getUuid')->willReturn($uuid);

		return new ObjectDeletedEvent($object);

	}//end deletionOf()

	/**
	 * A deletion clears BOTH tables, not one of them.
	 *
	 * @return void
	 */
	public function testADeletionClearsBothTables(): void {
		$this->favourites->expects($this->once())
			->method('cleanupForObject')
			->with('uuid-case-1');
		$this->views->expects($this->once())
			->method('cleanupForObject')
			->with('uuid-case-1');

		$this->listener->handle($this->deletionOf(uuid: 'uuid-case-1'));

	}//end testADeletionClearsBothTables()

	/**
	 * An object with no uuid clears nothing.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutAUuidClearsNothing(): void {
		$this->favourites->expects($this->never())->method('cleanupForObject');
		$this->views->expects($this->never())->method('cleanupForObject');

		$this->listener->handle($this->deletionOf(uuid: ''));

	}//end testAnObjectWithoutAUuidClearsNothing()

	/**
	 * Some other event is not this listener's business.
	 *
	 * The control: without it, a listener that cleared on EVERY event would
	 * pass the test above and quietly empty both tables on unrelated writes.
	 *
	 * @return void
	 */
	public function testAnUnrelatedEventIsIgnored(): void {
		$this->favourites->expects($this->never())->method('cleanupForObject');
		$this->views->expects($this->never())->method('cleanupForObject');

		$this->listener->handle(new Event());

	}//end testAnUnrelatedEventIsIgnored()

	/**
	 * A failing cleanup never propagates out of the deletion.
	 *
	 * @return void
	 */
	public function testAFailingCleanupIsSwallowed(): void {
		$this->favourites->method('cleanupForObject')
			->willThrowException(new \RuntimeException('db down'));

		$this->listener->handle($this->deletionOf(uuid: 'uuid-case-1'));

		$this->addToAssertionCount(count: 1);

	}//end testAFailingCleanupIsSwallowed()
}//end class
