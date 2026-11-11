<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Listener\ObjectCleanupListener}.
 *
 * Pins the exact deferral (job class, entry, chunk size, dedupe key), the
 * inline fallback when deferral is off, and the events and objects that are
 * ignored. Written against the code before its refactor and left unchanged
 * by it.
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

use OCA\OpenRegister\BackgroundJob\ObjectCleanupJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\ObjectCleanupListener;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\ObjectRelationCleanupService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @no-named-arguments
 */
class ObjectCleanupListenerGoldenTest extends TestCase {

	/**
	 * Every collaborator call, in order, JSON-encoded.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Dispatch one event and return the calls it caused.
	 *
	 * @param Event $event    The event.
	 * @param bool  $deferral Whether deferral is enabled.
	 *
	 * @return list<string>
	 */
	private function dispatch(Event $event, bool $deferral): array {
		$deferrals = $this->createMock(ListenerDeferralService::class);
		$deferrals->method('isDeferralEnabled')->willReturn($deferral);
		$deferrals->method('defer')->willReturnCallback(
			function (...$args): void {
				$this->calls[] = 'defer:' . json_encode($args);
			}
		);
		$cleanup = $this->createMock(ObjectRelationCleanupService::class);
		$cleanup->method('cleanup')->willReturnCallback(
			function (...$args): void {
				$this->calls[] = 'cleanup:' . json_encode($args);
			}
		);

		(new ObjectCleanupListener($deferrals, $cleanup))->handle($event);

		return $this->calls;
	}//end dispatch()

	/**
	 * A deleted object.
	 *
	 * @param string|null $uuid     The uuid.
	 * @param string|null $register The register.
	 * @param string|null $schema   The schema.
	 *
	 * @return ObjectDeletedEvent
	 */
	private static function deleted(?string $uuid, ?string $register = '7', ?string $schema = '228'): ObjectDeletedEvent {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister($register);
		$object->setSchema($schema);

		return new ObjectDeletedEvent($object);
	}//end deleted()

	/**
	 * @return void
	 */
	public function testDefersWithRegisterSchemaAndDedupeKey(): void {
		$this->assertSame(
			['defer:' . json_encode(
				[
					ObjectCleanupJob::class,
					['uuid' => 'abc-123', 'register' => '7', 'schema' => '228'],
					ListenerDeferralService::DEFAULT_CHUNK_SIZE,
					'abc-123',
				]
			)],
			$this->dispatch(self::deleted('abc-123'), true)
		);
	}//end testDefersWithRegisterSchemaAndDedupeKey()

	/**
	 * @return void
	 */
	public function testNullRegisterAndSchemaDeferAsEmptyStrings(): void {
		$this->assertSame(
			['defer:' . json_encode(
				[
					ObjectCleanupJob::class,
					['uuid' => 'abc-123', 'register' => '', 'schema' => ''],
					ListenerDeferralService::DEFAULT_CHUNK_SIZE,
					'abc-123',
				]
			)],
			$this->dispatch(self::deleted('abc-123', null, null), true)
		);
	}//end testNullRegisterAndSchemaDeferAsEmptyStrings()

	/**
	 * @return void
	 */
	public function testCleansInlineWhenDeferralIsOff(): void {
		$this->assertSame(
			['cleanup:' . json_encode(['abc-123'])],
			$this->dispatch(self::deleted('abc-123'), false)
		);
	}//end testCleansInlineWhenDeferralIsOff()

	/**
	 * @return void
	 */
	public function testObjectWithoutUuidIsIgnored(): void {
		$this->assertSame([], $this->dispatch(self::deleted(null), true));
		$this->assertSame([], $this->dispatch(self::deleted(''), false));
	}//end testObjectWithoutUuidIsIgnored()

	/**
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$object = new ObjectEntity();
		$object->setUuid('abc-123');
		$this->assertSame([], $this->dispatch(new ObjectUpdatedEvent($object), true));
		$this->assertSame([], $this->dispatch(new Event(), false));
	}//end testOtherEventsAreIgnored()
}//end class
