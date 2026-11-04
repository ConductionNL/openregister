<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CircularReferenceException;
use OCA\OpenRegister\Exception\ReferenceValidationException;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TmloService;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * SaveObject::validateReferenceExists(): what it looks up, what it caches,
 * which events it sends, and when it refuses.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Event\ReferenceValidatedEvent
 * @uses \OCA\OpenRegister\Event\ReferenceValidationFailedEvent
 * @uses \OCA\OpenRegister\Exception\CircularReferenceException
 * @uses \OCA\OpenRegister\Exception\ReferenceValidationException
 * @uses \OCA\OpenRegister\Exception\ValidationException
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugBatch
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugClientValue
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugLog
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugSource
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugStore
 */
final class SaveObjectReferenceExistsTest extends TestCase {

	private const UUID = '33333333-3333-3333-3333-333333333333';

	/** @var list<mixed> */
	private array $trace = [];

	/**
	 * A handler whose object lookup answers as the case says: found, missing or failing.
	 *
	 * @param string $lookup `found`, `missing` or `fails`.
	 * @param bool   $registerKnown Whether register 5 resolves.
	 */
	private function handler(string $lookup, bool $registerKnown = true): SaveObject {
		$schema = new Schema();
		$schema->setId(2);
		$schema->setSlug('note');
		$register = new Register();
		$register->setId(5);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(function ($id) use ($register, $registerKnown): Register {
			$this->trace[] = ['findRegister', $id];
			if ($registerKnown === false) {
				throw new DoesNotExistException('no register');
			}

			return $register;
		});
		$unified = $this->createMock(MagicMapper::class);
		$unified->method('find')->willReturnCallback(function (...$args) use ($lookup): ObjectEntity {
			$this->trace[] = ['find', $args[0], $args[1]?->getId(), $args[2]?->getId(), $args[3], $args[4], $args[5]];
			if ($lookup === 'missing') {
				throw new DoesNotExistException('gone');
			}

			if ($lookup === 'fails') {
				throw new RuntimeException('database down');
			}

			return new ObjectEntity();
		});
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			$this->trace[] = ['event', (new ReflectionClass($event))->getShortName(), $event->getPropertyName(), $event->getReferencedUuid(), $event->getTargetSchemaSlug(), $event->getTargetRegister()];
		});

		return new SaveObject(
			$this->createMock(MagicMapper::class),
			$unified,
			$this->createMock(MetadataHydrationHandler::class),
			$this->createMock(FilePropertyHandler::class),
			$this->createMock(LinkedEntityPropertyHandler::class),
			$this->createMock(IUserSession::class),
			$this->createMock(AuditTrailMapper::class),
			$schemas,
			$registers,
			$this->createMock(IURLGenerator::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$this->createMock(PropertyRbacHandler::class),
			$this->createMock(ComputedFieldHandler::class),
			$this->createMock(TranslationHandler::class),
			$this->createMock(TranslationProjectionService::class),
			$this->createMock(TranslationStatusService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(TmloService::class),
			$this->createMock(FolderManagementHandler::class),
			$this->createMock(PermissionHandler::class),
			eventDispatcher: $events
		);
	}

	/**
	 * Check one reference, twice, and return the trace and the outcome of each call.
	 */
	private function checkTwice(SaveObject $handler, ?string $register): array {
		$method = (new ReflectionClass(SaveObject::class))->getMethod('validateReferenceExists');
		$outcomes = [];
		for ($i = 0; $i < 2; $i++) {
			try {
				$method->invoke($handler, 'author', self::UUID, '2', $register);
				$outcomes[] = 'ok';
			} catch (ReferenceValidationException $e) {
				$outcomes[] = 'refused ' . $e->getTargetSchemaSlug();
			}
		}

		return ['outcomes' => $outcomes, 'trace' => $this->trace];
	}

	public function testAFoundReferenceIsLookedUpOnceInItsRegisterAndSchemaAndAnnounced(): void {
		$this->assertSame(
			[
				'outcomes' => ['ok', 'ok'],
				'trace' => [
					['findRegister', '5'],
					['find', self::UUID, 5, 2, false, false, false],
					['event', 'ReferenceValidatedEvent', 'author', self::UUID, 'note', '5'],
				],
			],
			$this->checkTwice($this->handler(lookup: 'found'), '5')
		);
	}

	public function testAMissingReferenceIsRefusedOnceAnnouncedAndThenReplayed(): void {
		$this->assertSame(
			[
				'outcomes' => ['refused note', 'refused 2'],
				'trace' => [
					['find', self::UUID, null, 2, false, false, false],
					['event', 'ReferenceValidationFailedEvent', 'author', self::UUID, 'note', null],
				],
			],
			$this->checkTwice($this->handler(lookup: 'missing'), null)
		);
	}

	public function testAFailingLookupLetsTheReferencePassUncached(): void {
		$this->assertSame(
			[
				'outcomes' => ['ok', 'ok'],
				'trace' => [
					['find', self::UUID, null, 2, false, false, false],
					['find', self::UUID, null, 2, false, false, false],
				],
			],
			$this->checkTwice($this->handler(lookup: 'fails'), null)
		);
	}

	public function testAnUnknownRegisterSkipsTheLookup(): void {
		$this->assertSame(
			['outcomes' => ['ok', 'ok'], 'trace' => [['findRegister', '5'], ['findRegister', '5']]],
			$this->checkTwice($this->handler(lookup: 'found', registerKnown: false), '5')
		);
	}

	public function testAReferenceOntoAnObjectBeingSavedIsACycle(): void {
		$handler = $this->handler(lookup: 'found');
		$push = (new ReflectionClass(SaveObject::class))->getMethod('pushSaveCallFrame');
		$push->invoke($handler, 'note', self::UUID, 'r1');

		try {
			(new ReflectionClass(SaveObject::class))->getMethod('validateReferenceExists')->invoke($handler, 'author', self::UUID, '2', null);
			$this->fail('no cycle refused');
		} catch (CircularReferenceException $e) {
			$this->assertSame(self::UUID, $e->getReferencedUuid());
			$this->assertSame('2', $e->getTargetSchemaSlug());
			$this->assertSame([['schema' => 'note', 'uuid' => self::UUID, 'register' => 'r1']], $e->getCycle());
			$this->assertSame(422, $e->getCode());
		}

		$this->assertSame([], $this->trace);
	}
}
