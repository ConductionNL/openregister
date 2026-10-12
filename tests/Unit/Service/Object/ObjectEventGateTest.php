<?php

/**
 * Which event an object's save or delete dispatches, decided in one place.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\DraftCreatedEvent;
use OCA\OpenRegister\Event\DraftDeletedEvent;
use OCA\OpenRegister\Event\DraftUpdatedEvent;
use OCA\OpenRegister\Event\ObjectActivatedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Object\ObjectEventGate;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Decision 227: a listener on object creation never sees a draft, and sees every object once.
 *
 * The dispatcher here delivers the way Nextcloud's does: to the listeners
 * registered on the event's own class (`dispatch(get_class($event), $event)`),
 * so a listener on ObjectCreatedEvent does not receive a DraftCreatedEvent
 * although one extends the other.
 *
 * @covers \OCA\OpenRegister\Service\Object\ObjectEventGate
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Event\DraftCreatedEvent
 * @uses \OCA\OpenRegister\Event\DraftUpdatedEvent
 * @uses \OCA\OpenRegister\Event\DraftDeletedEvent
 * @uses \OCA\OpenRegister\Event\ObjectCreatedEvent
 * @uses \OCA\OpenRegister\Event\ObjectUpdatedEvent
 * @uses \OCA\OpenRegister\Event\ObjectDeletedEvent
 * @uses \OCA\OpenRegister\Event\ObjectActivatedEvent
 */
class ObjectEventGateTest extends TestCase {

	/**
	 * What each kind of listener received, in order: "<listener>: <event class>".
	 *
	 * @var array<int, string>
	 */
	private array $received = [];

	private ObjectEventGate $gate;

	protected function setUp(): void {
		// A webhook-like listener on the object events, and a cache-like listener that is also on the draft events.
		$registered = [
			ObjectCreatedEvent::class => ['side effect', 'bookkeeping'],
			ObjectUpdatedEvent::class => ['side effect', 'bookkeeping'],
			ObjectDeletedEvent::class => ['side effect', 'bookkeeping'],
			ObjectActivatedEvent::class => ['receipt'],
			DraftCreatedEvent::class => ['bookkeeping'],
			DraftUpdatedEvent::class => ['bookkeeping'],
			DraftDeletedEvent::class => ['bookkeeping'],
		];
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($registered): void {
				foreach (($registered[get_class($event)] ?? []) as $listener) {
					$this->received[] = $listener . ': ' . substr((string)strrchr(get_class($event), '\\'), 1);
				}
			}
		);
		$this->gate = new ObjectEventGate(events: $events);
	}//end setUp()

	/**
	 * An object in the given stored status.
	 */
	private function object(?string $status): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('3f0d6c1e-0000-4000-8000-000000000001');
		$object->setStatus($status);

		return $object;
	}//end object()

	/**
	 * Spec scenario: a draft's first save, its autosaves and its delete reach bookkeeping alone.
	 */
	public function testADraftNeverReachesASideEffect(): void {
		$draft = $this->object(status: 'draft');

		$this->gate->created(object: $draft);
		$this->gate->updated(new: $draft, old: $draft);
		$this->gate->updated(new: $draft);
		$this->gate->deleted(object: $draft);

		$this->assertSame(
			['bookkeeping: DraftCreatedEvent', 'bookkeeping: DraftUpdatedEvent', 'bookkeeping: DraftUpdatedEvent', 'bookkeeping: DraftDeletedEvent'],
			$this->received
		);
	}//end testADraftNeverReachesASideEffect()

	/**
	 * Spec scenario: leaving draft runs the creation side effects once, then the receipt, and no "updated".
	 */
	public function testLeavingDraftIsItsCreation(): void {
		$draft = $this->object(status: 'draft');
		$active = $this->object(status: 'active');

		// The save that leaves draft, then the announcement by the path that asked for it.
		$this->gate->updated(new: $active, old: $draft);
		$this->gate->leftDraft(object: $active, receivedAt: new DateTimeImmutable('2026-10-12T16:00:00+02:00'));

		$this->assertSame(
			['bookkeeping: DraftUpdatedEvent', 'side effect: ObjectCreatedEvent', 'bookkeeping: ObjectCreatedEvent', 'receipt: ObjectActivatedEvent'],
			$this->received
		);
		// Once: a "created" listener counts one object.
		$this->assertCount(1, array_filter($this->received, static fn (string $line): bool => $line === 'side effect: ObjectCreatedEvent'));
	}//end testLeavingDraftIsItsCreation()

	/**
	 * Control: an object that never was a draft dispatches what it always did.
	 *
	 * @dataProvider notADraft
	 */
	public function testAnObjectThatIsNoDraftIsUnchanged(?string $status): void {
		$object = $this->object(status: $status);

		$this->gate->created(object: $object);
		$this->gate->updated(new: $object, old: $object);
		$this->gate->updated(new: $object);
		$this->gate->deleted(object: $object);

		$this->assertSame(
			[
				'side effect: ObjectCreatedEvent',
				'bookkeeping: ObjectCreatedEvent',
				'side effect: ObjectUpdatedEvent',
				'bookkeeping: ObjectUpdatedEvent',
				'side effect: ObjectUpdatedEvent',
				'bookkeeping: ObjectUpdatedEvent',
				'side effect: ObjectDeletedEvent',
				'bookkeeping: ObjectDeletedEvent',
			],
			$this->received
		);
	}//end testAnObjectThatIsNoDraftIsUnchanged()

	/**
	 * Stored statuses that are not `draft`.
	 *
	 * @return array<string, array{0: string|null}>
	 */
	public static function notADraft(): array {
		return ['active' => ['active'], 'no stored status' => [null], 'a status that only looks like it' => ['Draft']];
	}//end notADraft()

	/**
	 * The draft events carry what their object counterparts carry, so the listeners on the draft path need no code of their own.
	 */
	public function testADraftEventIsReadLikeItsCounterpart(): void {
		$old = $this->object(status: 'draft');
		$new = $this->object(status: 'draft');

		$this->assertSame($new, (new DraftCreatedEvent(object: $new))->getObject());
		$this->assertSame($new, (new DraftDeletedEvent(object: $new))->getObject());
		$updated = new DraftUpdatedEvent(newObject: $new, oldObject: $old);
		$this->assertSame($new, $updated->getNewObject());
		$this->assertSame($old, $updated->getOldObject());
		$this->assertInstanceOf(ObjectUpdatedEvent::class, $updated);
	}//end testADraftEventIsReadLikeItsCounterpart()

	/**
	 * Before a write the gate answers the same question it answers after one: is this a creation.
	 */
	public function testTheGateSaysWhatACreationIsBeforeAWrite(): void {
		$this->assertTrue($this->gate->holdsCreation(object: $this->object(status: 'draft')));
		$this->assertFalse($this->gate->holdsCreation(object: $this->object(status: 'active')));
		$this->assertFalse($this->gate->holdsCreation(object: $this->object(status: null)));

		$this->assertTrue($this->gate->leavesDraft(new: $this->object(status: 'active'), old: $this->object(status: 'draft')));
		// Controls: an autosave, an ordinary save, and a save whose stored object is not known.
		$this->assertFalse($this->gate->leavesDraft(new: $this->object(status: 'draft'), old: $this->object(status: 'draft')));
		$this->assertFalse($this->gate->leavesDraft(new: $this->object(status: 'active'), old: $this->object(status: 'active')));
		$this->assertFalse($this->gate->leavesDraft(new: $this->object(status: 'active'), old: null));
		$this->assertSame([], $this->received);
	}//end testTheGateSaysWhatACreationIsBeforeAWrite()
}//end class
