<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Listener\AdministeredValidationListener}.
 *
 * Pins which objects the enforcer is asked about for a create and an update,
 * that a refusal lands on the event verbatim and stops it, that no refusal
 * leaves the event untouched, and that other events are ignored. Written
 * against the code before its refactor and left unchanged by it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\AdministeredValidationListener;
use OCA\OpenRegister\Service\Rules\AdministeredValidationEnforcer;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @no-named-arguments
 */
class AdministeredValidationListenerGoldenTest extends TestCase {

	/**
	 * Every enforcer call, as [new uuid, old uuid or null].
	 *
	 * @var list<array{0: string|null, 1: string|null}>
	 */
	private array $asked = [];

	/**
	 * Dispatch one event through a listener whose enforcer answers $refusal.
	 *
	 * @param Event                     $event   The event.
	 * @param array<string, mixed>|null $refusal What refusalFor() answers.
	 *
	 * @return void
	 */
	private function dispatch(Event $event, ?array $refusal): void {
		$enforcer = $this->createMock(AdministeredValidationEnforcer::class);
		$enforcer->method('refusalFor')->willReturnCallback(
			function (ObjectEntity $new, ?ObjectEntity $old) use ($refusal): ?array {
				$this->asked[] = [$new->getUuid(), $old?->getUuid()];
				return $refusal;
			}
		);

		(new AdministeredValidationListener($enforcer))->handle($event);
	}//end dispatch()

	/**
	 * An object with the given uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private static function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);

		return $object;
	}//end object()

	/**
	 * @return void
	 */
	public function testRefusedCreateCarriesErrorsAndStops(): void {
		$event = new ObjectCreatingEvent(self::object('new-1'));
		$this->dispatch($event, ['amount' => ['must be positive']]);
		$this->assertSame([['new-1', null]], $this->asked);
		$this->assertSame(['amount' => ['must be positive']], $event->getErrors());
		$this->assertTrue($event->isPropagationStopped());
	}//end testRefusedCreateCarriesErrorsAndStops()

	/**
	 * @return void
	 */
	public function testRefusedUpdateAsksWithBothObjects(): void {
		$event = new ObjectUpdatingEvent(self::object('new-2'), self::object('old-2'));
		$this->dispatch($event, ['status' => 'locked']);
		$this->assertSame([['new-2', 'old-2']], $this->asked);
		$this->assertSame(['status' => 'locked'], $event->getErrors());
		$this->assertTrue($event->isPropagationStopped());
	}//end testRefusedUpdateAsksWithBothObjects()

	/**
	 * @return void
	 */
	public function testUpdateWithoutStoredObjectAsksWithNull(): void {
		$event = new ObjectUpdatingEvent(self::object('new-3'));
		$this->dispatch($event, null);
		$this->assertSame([['new-3', null]], $this->asked);
	}//end testUpdateWithoutStoredObjectAsksWithNull()

	/**
	 * @return void
	 */
	public function testAllowedWritesLeaveTheEventUntouched(): void {
		$create = new ObjectCreatingEvent(self::object('new-4'));
		$this->dispatch($create, null);
		$this->assertSame([], $create->getErrors());
		$this->assertFalse($create->isPropagationStopped());

		$update = new ObjectUpdatingEvent(self::object('new-5'), self::object('old-5'));
		$this->dispatch($update, null);
		$this->assertSame([], $update->getErrors());
		$this->assertFalse($update->isPropagationStopped());
	}//end testAllowedWritesLeaveTheEventUntouched()

	/**
	 * @return void
	 */
	public function testOtherEventsAreNotAsked(): void {
		$this->dispatch(new ObjectCreatedEvent(self::object('new-6')), ['x' => 'y']);
		$this->dispatch(new Event(), ['x' => 'y']);
		$this->assertSame([], $this->asked);
	}//end testOtherEventsAreNotAsked()
}//end class
