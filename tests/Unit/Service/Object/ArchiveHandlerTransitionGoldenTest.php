<?php

/**
 * ArchiveHandler transition characterisation.
 *
 * Pins, for each of the four verbs, the lookup, the permission question, the
 * write, the audit action, the logged reason and the answer, and the
 * no-op answer when the object is already in the asked state.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\ArchiveHandler;
use OCA\OpenRegister\Service\Object\ObjectStateRequest;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden transitions of ArchiveHandler.
 */
final class ArchiveHandlerTransitionGoldenTest extends TestCase {

	private const OBJ = 'obj-11111111-2222-3333-4444-555555555555';

	/** @var list<string> Every side effect, in order. */
	private array $events = [];

	private ObjectEntity $object;

	/**
	 * A handler over one archivable object.
	 *
	 * @return ArchiveHandler The handler.
	 */
	private function handler(): ArchiveHandler {
		$this->object = new ObjectEntity();
		$this->object->setUuid(self::OBJ);
		$this->object->setOwner('owner-uid');
		$schema = new Schema();
		$schema->setId(2);
		$schema->setSlug('zaak');
		$schema->setConfiguration([Schema::ARCHIVE_ANNOTATION => ['enabled' => true]]);
		$register = new Register();
		$register->setId(1);
		$register->setSlug('zaken');

		$magic = $this->createMock(MagicMapper::class);
		$magic->method('findAcrossAllSources')->willReturnCallback(
			function (...$args) use ($register, $schema): array {
				$this->events[] = 'find:' . json_encode(array_slice($args, 0, 4));
				return ['object' => $this->object, 'register' => $register, 'schema' => $schema];
			}
		);
		$magic->method('updateObjectEntity')->willReturnCallback(
			function (ObjectEntity $entity, ?Register $register=null, ?Schema $schema=null, ?ObjectEntity $oldEntity=null): ObjectEntity {
				$this->events[] = 'update:' . json_encode([$register?->getId(), $schema?->getId(), $oldEntity !== $entity]);
				return $entity;
			}
		);
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('createAuditTrail')->willReturnCallback(
			function (?ObjectEntity $old=null, ?ObjectEntity $new=null, ?string $action='update'): AuditTrail {
				$this->events[] = 'audit:' . $action;
				return new AuditTrail();
			}
		);
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('checkPermission')->willReturnCallback(
			function (Schema $schema, string $action, ?string $userId=null, ?string $objectOwner=null, bool $_rbac=true, ?ObjectEntity $object=null): void {
				$this->events[] = 'check:' . $action . ':' . $userId . ':' . $objectOwner . ':' . json_encode([$_rbac, $object === $this->object]);
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function ($message, array $context=[]): void {
				$this->events[] = 'info:' . $message . ':' . json_encode($context['reason'] ?? null);
			}
		);

		return new ArchiveHandler($permissions, $session, new \OCA\OpenRegister\Service\Object\ObjectChangeRecording($magic, $audit, $logger, $this->createStub(\OCP\EventDispatcher\IEventDispatcher::class)));
	}//end handler()

	public function testArchiveThenUnarchive(): void {
		$handler = $this->handler();
		$archived = $handler->archiveWith(new \OCA\OpenRegister\Service\Object\ObjectStateRequest(self::OBJ, 'done', 'zaken', '2'));
		$this->assertSame(self::OBJ, $archived['uuid']);
		$this->assertSame($this->object->getArchived(), $archived['archived']);
		$this->assertSame(
			[
				'find:' . json_encode([self::OBJ, false, true, true]),
				'check:update:anna:owner-uid:[true,true]',
				'update:' . json_encode([1, 2, true]),
				'audit:archive',
				'info:[ArchiveHandler] archive:null',
			],
			$this->events
		);

		$this->events = [];
		$this->assertSame(['uuid' => self::OBJ, 'archived' => null], $handler->unarchiveWith(new \OCA\OpenRegister\Service\Object\ObjectStateRequest(self::OBJ, 'back', '1', 'zaak')));
		$this->assertSame(['find:' . json_encode([self::OBJ, false, true, true]), 'check:update:anna:owner-uid:[true,true]', 'update:' . json_encode([1, 2, true]), 'audit:unarchive', 'info:[ArchiveHandler] unarchive:"back"'], $this->events);

		$this->events = [];
		$this->assertSame(['uuid' => self::OBJ, 'archived' => null], $handler->unarchiveWith(new \OCA\OpenRegister\Service\Object\ObjectStateRequest(self::OBJ, null, null, null)));
		$this->assertSame(['find:' . json_encode([self::OBJ, false, true, true]), 'check:update:anna:owner-uid:[true,true]'], $this->events);
	}

	public function testArchivingAnArchivedObjectKeepsTheMarker(): void {
		$handler = $this->handler();
		$first = $handler->archiveWith(new \OCA\OpenRegister\Service\Object\ObjectStateRequest(self::OBJ, 'done', null, null));
		$this->events = [];
		$this->assertSame($first, $handler->archiveWith(new \OCA\OpenRegister\Service\Object\ObjectStateRequest(self::OBJ, 'again', null, null)));
		$this->assertSame(['find:' . json_encode([self::OBJ, false, true, true]), 'check:update:anna:owner-uid:[true,true]'], $this->events);
	}

	public function testFreezeThenUnfreeze(): void {
		$handler = $this->handler();
		$frozen = $handler->freeze(new ObjectStateRequest(self::OBJ, 'hold', 'zaken', 'zaak'), 'besluit');
		$this->assertSame(self::OBJ, $frozen['uuid']);
		$this->assertSame($this->object->getFrozen(), $frozen['frozen']);
		$this->assertSame(['find:' . json_encode([self::OBJ, false, true, true]), 'check:update:anna:owner-uid:[true,true]', 'update:' . json_encode([1, 2, true]), 'audit:freeze', 'info:[ArchiveHandler] freeze:null'], $this->events);

		$this->events = [];
		$this->assertSame($frozen, $handler->freeze(new ObjectStateRequest(self::OBJ, null, null, null)));
		$this->assertSame(['find:' . json_encode([self::OBJ, false, true, true]), 'check:update:anna:owner-uid:[true,true]'], $this->events);

		$this->events = [];
		$this->assertSame(['uuid' => self::OBJ, 'frozen' => null], $handler->unfreezeWith(new \OCA\OpenRegister\Service\Object\ObjectStateRequest(self::OBJ, 'release', null, null)));
		$this->assertSame(['find:' . json_encode([self::OBJ, false, true, true]), 'check:update:anna:owner-uid:[true,true]', 'update:' . json_encode([1, 2, true]), 'audit:unfreeze', 'info:[ArchiveHandler] unfreeze:"release"'], $this->events);

		$this->events = [];
		$this->assertSame(['uuid' => self::OBJ, 'frozen' => null], $handler->unfreezeWith(new \OCA\OpenRegister\Service\Object\ObjectStateRequest(self::OBJ, null, null, null)));
		$this->assertSame(['find:' . json_encode([self::OBJ, false, true, true]), 'check:update:anna:owner-uid:[true,true]'], $this->events);
	}

	public function testOutOfScopeIsNotFoundBeforeThePermissionQuestion(): void {
		$handler = $this->handler();
		foreach ([['other', null, 'register "other"'], [null, 'other', 'schema "other"']] as [$register, $schema, $where]) {
			$this->events = [];
			try {
				$handler->freeze(new ObjectStateRequest(self::OBJ, null, $register, $schema));
				$this->fail('expected not found');
			} catch (DoesNotExistException $e) {
				$this->assertSame('Object "' . self::OBJ . '" is not in ' . $where . '.', $e->getMessage());
			}

			$this->assertSame(['find:' . json_encode([self::OBJ, false, true, true])], $this->events);
		}
	}
}//end class
