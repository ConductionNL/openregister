<?php

/**
 * Behaviour of the small task-area listeners that had no test of their own:
 * the read-state and watcher prunes, read-state invalidation and the state
 * history projection. Written against the code before it was cut into small
 * units, and kept as its pin.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Listener\ReadStateInvalidationListener;
use OCA\OpenRegister\Listener\ReadStatePruneListener;
use OCA\OpenRegister\Listener\StateHistoryProjectionListener;
use OCA\OpenRegister\Listener\WatcherPruneListener;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationClearingServiceInterface;
use OCA\OpenRegister\Service\History\StateHistoryProjector;
use OCA\OpenRegister\Service\Interaction\ReadStateService;
use OCA\OpenRegister\Service\Interaction\SubstantiveChangeEvaluatorInterface;
use OCA\OpenRegister\Service\Interaction\WatcherServiceInterface;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * What each listener does, what it skips, and that a failure never escapes.
 */
final class TaskAreaListenersBehaviourTest extends TestCase {

	/**
	 * A deleted object's read states are removed and its orphaned notices archived.
	 *
	 * @return void
	 */
	public function testReadStatesArePrunedOnDelete(): void {
		$readState = $this->createMock(ReadStateService::class);
		$readState->expects(self::once())->method('cleanupForObject')->with('uuid-1');
		$clearing = $this->createMock(NotificationClearingServiceInterface::class);
		$clearing->expects(self::once())->method('archiveOrphaned')->with('uuid-1');
		$listener = new ReadStatePruneListener($readState, $clearing, new NullLogger());

		$listener->handle(new ObjectDeletedEvent($this->object(uuid: 'uuid-1')));
		$listener->handle(new ObjectDeletedEvent(new ObjectEntity()));
		$listener->handle(new ObjectCreatedEvent($this->object(uuid: 'uuid-1')));
	}//end testReadStatesArePrunedOnDelete()

	/**
	 * A failed read-state prune is logged at debug and never thrown.
	 *
	 * @return void
	 */
	public function testAFailedReadStatePruneIsSwallowed(): void {
		$readState = $this->createMock(ReadStateService::class);
		$readState->method('cleanupForObject')->willThrowException(new \RuntimeException('gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('debug')->with('[ReadStatePruneListener] prune skipped: gone');

		(new ReadStatePruneListener($readState, $this->createMock(NotificationClearingServiceInterface::class), $logger))
			->handle(new ObjectDeletedEvent($this->object(uuid: 'uuid-1')));
	}//end testAFailedReadStatePruneIsSwallowed()

	/**
	 * A deleted object's watchers are removed; a failure is logged and swallowed.
	 *
	 * @return void
	 */
	public function testWatchersArePrunedOnDelete(): void {
		$watchers = $this->createMock(WatcherServiceInterface::class);
		$watchers->expects(self::exactly(2))->method('cleanupForObject')->with('uuid-1')
			->willReturnOnConsecutiveCalls(1, self::throwException(new \RuntimeException('gone')));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('debug')->with('[WatcherPruneListener] prune skipped: gone');
		$listener = new WatcherPruneListener($watchers, $logger);

		$listener->handle(new ObjectDeletedEvent($this->object(uuid: 'uuid-1')));
		$listener->handle(new ObjectDeletedEvent(new ObjectEntity()));
		$listener->handle(new ObjectUpdatedEvent($this->object(uuid: 'uuid-1'), null));
		$listener->handle(new ObjectDeletedEvent($this->object(uuid: 'uuid-1')));
	}//end testWatchersArePrunedOnDelete()

	/**
	 * A substantive update invalidates read states for everyone but the actor.
	 *
	 * @return void
	 */
	public function testASubstantiveUpdateInvalidatesReadStates(): void {
		$readState = $this->createMock(ReadStateService::class);
		$readState->expects(self::once())->method('invalidate')->with('uuid-1', 'jan');

		$this->invalidationListener($readState, substantive: true)
			->handle(new ObjectUpdatedEvent($this->object(uuid: 'uuid-1'), $this->object(uuid: 'uuid-1')));
	}//end testASubstantiveUpdateInvalidatesReadStates()

	/**
	 * A technical touch, a uuid-less object or another event invalidates nothing.
	 *
	 * @return void
	 */
	public function testNothingElseInvalidatesReadStates(): void {
		$readState = $this->createMock(ReadStateService::class);
		$readState->expects(self::never())->method('invalidate');
		$technical = $this->invalidationListener($readState, substantive: false);
		$substantive = $this->invalidationListener($readState, substantive: true);

		$technical->handle(new ObjectUpdatedEvent($this->object(uuid: 'uuid-1'), $this->object(uuid: 'uuid-1')));
		$substantive->handle(new ObjectUpdatedEvent(new ObjectEntity(), null));
		$substantive->handle(new ObjectCreatedEvent($this->object(uuid: 'uuid-1')));
	}//end testNothingElseInvalidatesReadStates()

	/**
	 * A transition is projected with its schema, register and target state.
	 *
	 * @return void
	 */
	public function testATransitionIsProjected(): void {
		$schema = new Schema();
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);
		$projector = $this->createMock(StateHistoryProjector::class);
		$projector->expects(self::once())->method('record')
			->with(self::callback(static fn (ObjectTransitionedEvent $move): bool => $move->getObject()->getUuid() === 'uuid-1' && $move->getRegister() === '3' && $move->getTo() === 'closed'), $schema, self::anything())
			->willReturn(true);
		$listener = new StateHistoryProjectionListener($projector, $schemas, new NullLogger());

		$listener->handle($this->transition(object: $this->object(uuid: 'uuid-1')));
		$listener->handle($this->transition(object: new ObjectEntity()));
		$listener->handle(new ObjectCreatedEvent($this->object(uuid: 'uuid-1')));
	}//end testATransitionIsProjected()

	/**
	 * An unresolvable schema projects with no schema; a projector failure is swallowed.
	 *
	 * @return void
	 */
	public function testAProjectionFailureNeverEscapes(): void {
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willThrowException(new \RuntimeException('no schema'));
		$projector = $this->createMock(StateHistoryProjector::class);
		$projector->expects(self::once())->method('record')
			->with(self::callback(static fn (ObjectTransitionedEvent $move): bool => $move->getObject()->getUuid() === 'uuid-1' && $move->getRegister() === '3' && $move->getTo() === 'closed'), null, self::anything())
			->willThrowException(new \RuntimeException('db gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		(new StateHistoryProjectionListener($projector, $schemas, $logger))
			->handle($this->transition(object: $this->object(uuid: 'uuid-1')));
	}//end testAProjectionFailureNeverEscapes()

	/**
	 * The invalidation listener with jan signed in.
	 *
	 * @param ReadStateService $readState   The read-state primitive.
	 * @param bool             $substantive What the evaluator answers.
	 *
	 * @return ReadStateInvalidationListener
	 */
	private function invalidationListener(ReadStateService $readState, bool $substantive): ReadStateInvalidationListener {
		$evaluator = $this->createMock(SubstantiveChangeEvaluatorInterface::class);
		$evaluator->method('isSubstantive')->willReturn($substantive);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ReadStateInvalidationListener($readState, $evaluator, $session, new NullLogger());
	}//end invalidationListener()

	/**
	 * An object in register 3, schema 12.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema('12');
		$object->setRegister('3');

		return $object;
	}//end object()

	/**
	 * A committed transition to `closed`.
	 *
	 * @param ObjectEntity $object The transitioned object.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function transition(ObjectEntity $object): ObjectTransitionedEvent {
		return new ObjectTransitionedEvent(object: $object, move: new TransitionMove(action: 'close', from: 'open', to: 'closed'), scope: new RegisterSchemaRef(register: '3', schema: '12'), actor: new TransitionActor(userId: 'jan'));
	}//end transition()
}//end class
