<?php

/**
 * StateEntryFreezeListener tests.
 *
 * The listener freezes an object when it enters a state whose block declares
 * `freezesObject: true`, and on no other transition. It writes the marker with
 * the state, records a `freeze` audit entry, keeps an existing freeze, and never
 * throws out of a committed transition.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Listener\StateEntryFreezeListener;
use OCA\OpenRegister\Listener\Support\EventSchemaLookup;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for StateEntryFreezeListener.
 *
 * @covers \OCA\OpenRegister\Listener\StateEntryFreezeListener
 * @uses   \OCA\OpenRegister\Listener\Support\EventSchemaLookup
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Event\ObjectTransitionedEvent
 * @uses   \OCA\OpenRegister\Service\Lifecycle\TransitionMove
 * @uses   \OCA\OpenRegister\Service\Lifecycle\TransitionActor
 * @uses   \OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef
 */
class StateEntryFreezeListenerTest extends TestCase {

	/**
	 * The object tables.
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper&MockObject $objectMapper;
	/**
	 * The schema lookup.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;
	/**
	 * The register lookup.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface&MockObject $registerMapper;
	/**
	 * Where the freeze is recorded.
	 *
	 * @var AuditTrailMapperInterface&MockObject
	 */
	private AuditTrailMapperInterface&MockObject $auditTrail;
	/**
	 * Where a failure is reported.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;
	/**
	 * The subject.
	 *
	 * @var StateEntryFreezeListener
	 */
	private StateEntryFreezeListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['updateObjectEntity'])
			->getMock();
		$this->schemaMapper = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$this->registerMapper = $this->createMock(RegisterMapperInterface::class);
		$this->auditTrail = $this->createMock(AuditTrailMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->listener = new StateEntryFreezeListener(
			new EventSchemaLookup(
				new SchemaCatalog($this->registerMapper, $this->schemaMapper, $this->objectMapper),
				$this->logger
			),
			$this->auditTrail
		);
	}

	/**
	 * A schema whose lifecycle declares the given state blocks.
	 *
	 * @param array<string, mixed> $states The `states` map.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWithStates(array $states): Schema {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-lifecycle' => ['field' => 'status', 'states' => $states]]);

		return $schema;
	}

	/**
	 * A transition event onto one state.
	 *
	 * @param string            $to     The state reached.
	 * @param string|null       $userId The actor, null for a move nobody made by hand.
	 * @param ObjectEntity|null $object The object; a fresh unfrozen one when null.
	 *
	 * @return ObjectTransitionedEvent The event.
	 */
	private function event(string $to, ?string $userId = 'els', ?ObjectEntity $object = null): ObjectTransitionedEvent {
		if ($object === null) {
			$object = new ObjectEntity();
			$object->setUuid('obj-1');
			$object->setRegister('7');
			$object->setSchema('9');
			$object->setFrozen(null);
		}

		return new ObjectTransitionedEvent(
			object: $object,
			move: new TransitionMove(action: 'afronden', from: 'in_behandeling', to: $to),
			scope: new RegisterSchemaRef(register: 'zaken', schema: 'zaak'),
			actor: new TransitionActor(userId: $userId),
		);
	}

	public function testEnteringAFreezingStateFreezesNamingTheStateAndAudits(): void {
		$this->schemaMapper->expects($this->once())->method('find')->with('9')
			->willReturn($this->schemaWithStates(['afgerond' => ['freezesObject' => true]]));
		$register = new Register();
		$this->registerMapper->expects($this->once())->method('find')->with('7')->willReturn($register);

		$written = null;
		$this->objectMapper->expects($this->once())->method('updateObjectEntity')
			->willReturnCallback(
				function (ObjectEntity $entity, Register $reg, Schema $schema, ?ObjectEntity $oldEntity) use (&$written, $register): ObjectEntity {
					$this->assertSame($register, $reg);
					$this->assertFalse($oldEntity->isFrozen(), 'the audit "before" is the unfrozen object');
					$written = $entity;
					return $entity;
				}
			);
		$this->auditTrail->expects($this->once())->method('createAuditTrail')
			->with($this->isInstanceOf(ObjectEntity::class), $this->isInstanceOf(ObjectEntity::class), 'freeze');

		$this->listener->handle($this->event('afgerond'));

		$this->assertNotNull($written);
		$frozen = $written->getFrozen();
		$this->assertSame('els', $frozen['by']);
		$this->assertSame('afgerond', $frozen['state']);
		$this->assertSame('Entered state "afgerond"', $frozen['reason']);
		$this->assertNotFalse(\DateTime::createFromFormat(\DateTimeInterface::ATOM, $frozen['at']));
	}

	public function testAMoveNobodyMadeByHandIsFrozenBySystem(): void {
		$this->schemaMapper->method('find')->willReturn($this->schemaWithStates(['afgerond' => ['freezesObject' => true]]));
		$this->registerMapper->method('find')->willReturn(new Register());
		$this->objectMapper->expects($this->once())->method('updateObjectEntity')->willReturnArgument(0);

		$event = $this->event('afgerond', null);
		$this->listener->handle($event);

		$this->assertSame(StateEntryFreezeListener::SYSTEM_ACTOR, $event->getObject()->getFrozen()['by']);
	}

	public function testAStateWithoutTheDeclarationFreezesNothing(): void {
		$this->schemaMapper->method('find')->willReturn(
			$this->schemaWithStates(['afgerond' => ['freezesObject' => true], 'in_behandeling' => ['fields' => ['readOnly' => [['fields' => ['x']]]]]])
		);
		$this->objectMapper->expects($this->never())->method('updateObjectEntity');
		$this->auditTrail->expects($this->never())->method('createAuditTrail');

		$event = $this->event('in_behandeling');
		$this->listener->handle($event);

		$this->assertFalse($event->getObject()->isFrozen());
	}

	public function testAnAlreadyFrozenObjectKeepsItsFirstFreeze(): void {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('9');
		$object->setFrozen(['by' => 'piet', 'at' => '2026-01-01T00:00:00+00:00', 'reason' => 'bezwaar', 'state' => null]);

		$this->schemaMapper->expects($this->never())->method('find');
		$this->objectMapper->expects($this->never())->method('updateObjectEntity');

		$this->listener->handle($this->event('afgerond', 'els', $object));

		$this->assertSame('piet', $object->getFrozen()['by']);
	}

	public function testAWriteFailureIsLoggedAndSwallowed(): void {
		$this->schemaMapper->method('find')->willReturn($this->schemaWithStates(['afgerond' => ['freezesObject' => true]]));
		$this->registerMapper->method('find')->willReturn(new Register());
		$this->objectMapper->method('updateObjectEntity')->willThrowException(new RuntimeException('table gone'));
		$this->auditTrail->expects($this->never())->method('createAuditTrail');
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('Could not freeze obj-1 on entering "afgerond": table gone'));

		$this->listener->handle($this->event('afgerond'));
	}

	public function testAnotherEventIsIgnored(): void {
		$this->schemaMapper->expects($this->never())->method('find');

		$this->listener->handle(new Event());
	}

	public function testStateFreezesReadsOnlyATrueDeclaration(): void {
		$this->assertTrue(StateEntryFreezeListener::stateFreezes($this->schemaWithStates(['a' => ['freezesObject' => true]]), 'a'));
		$this->assertFalse(StateEntryFreezeListener::stateFreezes($this->schemaWithStates(['a' => ['freezesObject' => 'true']]), 'a'));
		$this->assertFalse(StateEntryFreezeListener::stateFreezes($this->schemaWithStates(['a' => ['freezesObject' => false]]), 'a'));
		$this->assertFalse(
			StateEntryFreezeListener::stateFreezes($this->schemaWithStates(['a' => ['freezesObject' => true, 'enabled' => false]]), 'a'),
			'a block switched off declares nothing'
		);
		$this->assertFalse(StateEntryFreezeListener::stateFreezes($this->schemaWithStates(['a' => ['freezesObject' => true]]), 'b'));
		$this->assertFalse(StateEntryFreezeListener::stateFreezes(new Schema(), 'a'), 'no lifecycle, no freeze');
	}
}
