<?php

/**
 * A substantive update clears read state for everyone but the actor.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\ReadStateInvalidationListener;
use OCA\OpenRegister\Service\Interaction\ReadStateServiceInterface;
use OCA\OpenRegister\Service\Interaction\SubstantiveChangeEvaluatorInterface;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every branch: no uuid, not substantive, substantive with and without a user, failure, other event.
 */
class ReadStateInvalidationListenerBehaviourTest extends TestCase {
	private function object(?string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		return $object;
	}

	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$session->method('getUser')->willReturn($user);
		return $session;
	}

	private function evaluator(bool $substantive): SubstantiveChangeEvaluatorInterface {
		$evaluator = $this->createMock(SubstantiveChangeEvaluatorInterface::class);
		$evaluator->method('isSubstantive')->willReturn($substantive);
		return $evaluator;
	}

	public function testASubstantiveChangeInvalidatesWithTheActorExcluded(): void {
		$old       = $this->object('obj-1');
		$new       = $this->object('obj-1');
		$evaluator = $this->createMock(SubstantiveChangeEvaluatorInterface::class);
		$evaluator->expects($this->once())->method('isSubstantive')
			->with($this->identicalTo($new), $this->identicalTo($old))->willReturn(true);
		$readState = $this->createMock(ReadStateServiceInterface::class);
		$readState->expects($this->once())->method('invalidate')->with('obj-1', 'ann')->willReturn(3);

		(new ReadStateInvalidationListener($readState, $evaluator, $this->session('ann'), $this->createMock(LoggerInterface::class)))
			->handle(new ObjectUpdatedEvent($new, $old));
	}

	public function testWithoutASignedInUserTheActorIsNull(): void {
		$readState = $this->createMock(ReadStateServiceInterface::class);
		$readState->expects($this->once())->method('invalidate')->with('obj-1', null)->willReturn(1);

		(new ReadStateInvalidationListener($readState, $this->evaluator(true), $this->session(null), $this->createMock(LoggerInterface::class)))
			->handle(new ObjectUpdatedEvent($this->object('obj-1')));
	}

	public function testANonSubstantiveChangeKeepsReadState(): void {
		$readState = $this->createMock(ReadStateServiceInterface::class);
		$readState->expects($this->never())->method('invalidate');

		(new ReadStateInvalidationListener($readState, $this->evaluator(false), $this->session('ann'), $this->createMock(LoggerInterface::class)))
			->handle(new ObjectUpdatedEvent($this->object('obj-1')));
	}

	public function testAnObjectWithoutUuidIsSkippedBeforeEvaluation(): void {
		$evaluator = $this->createMock(SubstantiveChangeEvaluatorInterface::class);
		$evaluator->expects($this->never())->method('isSubstantive');
		$readState = $this->createMock(ReadStateServiceInterface::class);
		$readState->expects($this->never())->method('invalidate');

		(new ReadStateInvalidationListener($readState, $evaluator, $this->session('ann'), $this->createMock(LoggerInterface::class)))
			->handle(new ObjectUpdatedEvent($this->object(null)));
	}

	public function testAFailureIsLoggedAtDebugAndSwallowed(): void {
		$readState = $this->createMock(ReadStateServiceInterface::class);
		$readState->method('invalidate')->willThrowException(new RuntimeException('table missing'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')
			->with('[ReadStateInvalidationListener] invalidation skipped: table missing');

		(new ReadStateInvalidationListener($readState, $this->evaluator(true), $this->session('ann'), $logger))
			->handle(new ObjectUpdatedEvent($this->object('obj-1')));
	}

	public function testACreateEventIsIgnored(): void {
		$evaluator = $this->createMock(SubstantiveChangeEvaluatorInterface::class);
		$evaluator->expects($this->never())->method('isSubstantive');

		(new ReadStateInvalidationListener($this->createMock(ReadStateServiceInterface::class), $evaluator, $this->session('ann'), $this->createMock(LoggerInterface::class)))
			->handle(new ObjectCreatedEvent($this->object('obj-1')));
	}
}
