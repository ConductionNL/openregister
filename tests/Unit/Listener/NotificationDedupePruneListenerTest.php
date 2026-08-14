<?php

/**
 * Unit tests for NotificationDedupePruneListener.
 *
 * @category Tests\Unit\Listener
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Listener\NotificationDedupePruneListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the per-object dedup pruning listener.
 */
class NotificationDedupePruneListenerTest extends TestCase {

	public function testCallsDeleteByObjectOnObjectDeletedEvent(): void {
		$object = new ObjectEntity();
		$object->setUuid('aaaa-bbbb-cccc-dddd');

		$mapper = $this->createMock(NotificationDedupeStateMapper::class);
		$mapper->expects($this->once())
			->method('deleteByObject')
			->with('aaaa-bbbb-cccc-dddd');

		$listener = new NotificationDedupePruneListener($mapper, new NullLogger());
		$listener->handle(new ObjectDeletedEvent($object));
	}//end testCallsDeleteByObjectOnObjectDeletedEvent()

	public function testIgnoresUnrelatedEvents(): void {
		$mapper = $this->createMock(NotificationDedupeStateMapper::class);
		$mapper->expects($this->never())->method('deleteByObject');

		$listener = new NotificationDedupePruneListener($mapper, new NullLogger());
		$listener->handle(new Event());
	}//end testIgnoresUnrelatedEvents()

	public function testSkipsWhenUuidEmpty(): void {
		$object = new ObjectEntity();
		// No UUID set.

		$mapper = $this->createMock(NotificationDedupeStateMapper::class);
		$mapper->expects($this->never())->method('deleteByObject');

		$listener = new NotificationDedupePruneListener($mapper, new NullLogger());
		$listener->handle(new ObjectDeletedEvent($object));
	}//end testSkipsWhenUuidEmpty()

	public function testSwallowsMapperFailures(): void {
		$object = new ObjectEntity();
		$object->setUuid('aaaa-bbbb-cccc-dddd');

		$mapper = $this->createMock(NotificationDedupeStateMapper::class);
		$mapper->method('deleteByObject')->willThrowException(new \RuntimeException('db'));

		$listener = new NotificationDedupePruneListener($mapper, new NullLogger());
		$listener->handle(new ObjectDeletedEvent($object));

		$this->expectNotToPerformAssertions();
	}//end testSwallowsMapperFailures()
}//end class
