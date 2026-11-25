<?php

/**
 * HandlesObjectDeletion hands a deleted object with a uuid to its listener, ignores every other event and object, and turns a failure into the listener's own failure handling.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unit-test-coverage/specs/unit-test-coverage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Listener\Support\HandlesObjectDeletion;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The trait, exercised through a listener that records what it is handed.
 *
 * @covers \OCA\OpenRegister\Listener\Support\HandlesObjectDeletion
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses \OCA\OpenRegister\Event\ObjectDeletedEvent
 * @uses \OCA\OpenRegister\Event\ObjectCreatedEvent
 */
class HandlesObjectDeletionTest extends TestCase {

	/**
	 * A listener using the trait that records every call, and fails when asked to.
	 *
	 * @param Throwable|null $failure Thrown from onObjectDeleted.
	 *
	 * @return object
	 */
	private static function listener(?Throwable $failure = null): object {
		return new class($failure) {
			use HandlesObjectDeletion;

			/** @var list<array{0: ObjectEntity, 1: string}> */
			public array $deleted = [];

			/** @var list<Throwable> */
			public array $failures = [];

			public function __construct(private readonly ?Throwable $failure) {
			}

			private function onObjectDeleted(ObjectEntity $object, string $uuid): void {
				if ($this->failure !== null) {
					throw $this->failure;
				}

				$this->deleted[] = [$object, $uuid];
			}

			private function onDeletionFailure(Throwable $error): void {
				$this->failures[] = $error;
			}
		};
	}//end listener()

	private static function object(?string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		if ($uuid !== null) {
			$object->setUuid($uuid);
		}

		return $object;
	}//end object()

	public function testADeletedObjectIsHandedOverWithItsUuid(): void {
		$listener = self::listener();
		$object   = self::object('a1b2c3');

		$listener->handle(new ObjectDeletedEvent($object));

		self::assertSame([[$object, 'a1b2c3']], $listener->deleted);
		self::assertSame([], $listener->failures);
	}//end testADeletedObjectIsHandedOverWithItsUuid()

	public function testAnObjectWithoutAUuidIsIgnored(): void {
		$listener = self::listener();

		$listener->handle(new ObjectDeletedEvent(self::object(null)));
		$listener->handle(new ObjectDeletedEvent(self::object('')));

		self::assertSame([], $listener->deleted);
		self::assertSame([], $listener->failures);
	}//end testAnObjectWithoutAUuidIsIgnored()

	public function testAnyOtherEventIsIgnored(): void {
		$listener = self::listener();

		$listener->handle(new ObjectCreatedEvent(self::object('a1b2c3')));
		$listener->handle(new Event());

		self::assertSame([], $listener->deleted);
	}//end testAnyOtherEventIsIgnored()

	public function testAFailureGoesToTheFailureHandlerAndIsNotThrown(): void {
		$failure  = new RuntimeException('index unreachable');
		$listener = self::listener($failure);

		$listener->handle(new ObjectDeletedEvent(self::object('a1b2c3')));

		self::assertSame([$failure], $listener->failures);
	}//end testAFailureGoesToTheFailureHandlerAndIsNotThrown()
}//end class
