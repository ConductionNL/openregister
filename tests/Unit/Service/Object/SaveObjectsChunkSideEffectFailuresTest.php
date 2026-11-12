<?php

/**
 * A failing audit insert or event listener in a bulk chunk is logged and the rest still runs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Object\BulkRowChecks;
use OCA\OpenRegister\Service\Object\BulkSaveReporting;
use OCA\OpenRegister\Service\Object\BulkSaveSession;
use OCA\OpenRegister\Service\Object\BulkSaveStores;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkSaveRun;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins the failure handling of SaveObjects' chunk side effects.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Event\ObjectCreatedEvent
 * @uses   \OCA\OpenRegister\Event\ObjectUpdatedEvent
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\Object\ObjectEventGate
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 */
class SaveObjectsChunkSideEffectFailuresTest extends TestCase {

	private AuditTrailMapper&MockObject $audit;

	private IEventDispatcher&MockObject $events;

	private LoggerInterface&MockObject $logger;

	/**
	 * Logged warnings as [message, context].
	 *
	 * @var list<array{0: string, 1: array}>
	 */
	private array $warnings = [];

	protected function setUp(): void {
		parent::setUp();
		$this->audit = $this->createMock(AuditTrailMapper::class);
		$this->events = $this->createMock(IEventDispatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);
	}//end setUp()

	/**
	 * Run emitChunkSideEffects on a fresh SaveObjects.
	 *
	 * @param array $sideEffects The created and updated entities.
	 *
	 * @return void
	 */
	private function emit(array $sideEffects): void {
		$handler = new SaveObjects(
			stores: new BulkSaveStores(catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(MagicMapper::class)), saveHandler: $this->createMock(SaveObject::class)),
			session: new BulkSaveSession(userSession: $this->createMock(IUserSession::class), organisationService: $this->createMock(OrganisationService::class), groupManager: null),
			reporting: new BulkSaveReporting(logger: $this->logger, eventDispatcher: $this->events, auditTrailMapper: $this->audit),
			checks: new BulkRowChecks(permissionHandler: null, validateHandler: null)
		);
		$method = new ReflectionMethod(SaveObjects::class, 'emitChunkSideEffects');
		$method->invoke($handler, $sideEffects, self::bulkRun(events: true, audit: true));
	}//end emit()

	/**
	 * An entity with the given uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		return $entity;
	}//end entity()

	public function testAFailedAuditSliceIsLoggedAndTheNextSliceStillWritten(): void {
		$created = [];
		for ($i = 0; $i < 150; $i++) {
			$created[] = $this->entity('c-' . $i);
		}

		$sizes = [];
		$this->audit->expects($this->exactly(2))->method('insertAuditTrails')->willReturnCallback(
			function (array $entries) use (&$sizes): array {
				$sizes[] = count($entries);
				if (count($sizes) === 1) {
					throw new \RuntimeException('insert failed');
				}

				return [];
			}
		);
		$this->events->expects($this->exactly(150))->method('dispatchTyped');

		$this->emit(['created' => $created]);

		$this->assertSame([100, 50], $sizes);
		$this->assertSame(
			[['[SaveObjects] Failed to write batched audit trail for bulk chunk', ['error' => 'insert failed', 'entries' => 100]]],
			$this->warnings
		);
	}//end testAFailedAuditSliceIsLoggedAndTheNextSliceStillWritten()

	public function testAFailingListenerIsLoggedPerObjectAndTheOthersStillDispatched(): void {
		$old = $this->entity('u-1');
		$seen = [];
		$this->events->method('dispatchTyped')->willReturnCallback(
			function (object $event) use (&$seen): void {
				if ($event instanceof ObjectCreatedEvent) {
					$seen[] = 'created:' . $event->getObject()->getUuid();
				}

				if ($event instanceof ObjectUpdatedEvent) {
					$seen[] = 'updated:' . $event->getNewObject()->getUuid() . '<' . $event->getOldObject()?->getUuid();
				}

				throw new \RuntimeException('listener failed');
			}
		);

		$this->emit(
			[
				'created' => [$this->entity('c-1'), $this->entity('c-2')],
				'updated' => [['old' => $old, 'new' => $this->entity('u-1n')], ['old' => null, 'new' => $this->entity('u-2')]],
			]
		);

		$this->assertSame(['created:c-1', 'created:c-2', 'updated:u-1n<u-1', 'updated:u-2<u-2'], $seen);
		$this->assertSame(
			[
				['[SaveObjects] Failed to dispatch ObjectCreatedEvent for bulk object', ['error' => 'listener failed', 'uuid' => 'c-1']],
				['[SaveObjects] Failed to dispatch ObjectCreatedEvent for bulk object', ['error' => 'listener failed', 'uuid' => 'c-2']],
				['[SaveObjects] Failed to dispatch ObjectUpdatedEvent for bulk object', ['error' => 'listener failed', 'uuid' => 'u-1n']],
				['[SaveObjects] Failed to dispatch ObjectUpdatedEvent for bulk object', ['error' => 'listener failed', 'uuid' => 'u-2']],
			],
			$this->warnings
		);
	}//end testAFailingListenerIsLoggedPerObjectAndTheOthersStillDispatched()

	/**
	 * A bulk save run carrying the events and audit flags the side effects read.
	 *
	 * @param bool $events Whether lifecycle events are dispatched.
	 * @param bool $audit  Whether audit trail rows are written.
	 *
	 * @return BulkSaveRun
	 */
	private static function bulkRun(bool $events, bool $audit): BulkSaveRun {
		$run         = new BulkSaveRun();
		$run->events = $events;
		$run->audit  = $audit;

		return $run;
	}//end bulkRun()
}//end class
