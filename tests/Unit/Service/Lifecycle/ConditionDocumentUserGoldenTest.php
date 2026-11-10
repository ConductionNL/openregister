<?php

/**
 * Golden coverage for the `user` key of the condition documents.
 *
 * The transition condition evaluator and the state field rule resolver both
 * evaluate a condition against the session user; this pins the document each
 * builds with a signed-in user and without one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionReporting;
use OCA\OpenRegister\Service\Lifecycle\LifecycleRuleCaller;
use OCA\OpenRegister\Service\Lifecycle\LifecycleRuleCheck;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Locks the documents' user key, signed in and not.
 */
final class ConditionDocumentUserGoldenTest extends TestCase {

	/**
	 * A session for `alice` in two groups, or for nobody.
	 *
	 * @param boolean $signedIn Whether a user is signed in.
	 *
	 * @return array{0: IUserSession, 1: IGroupManager} The session and group manager.
	 */
	private function identity(bool $signedIn): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['admin', 'zaak']);

		return [$session, $groups];
	}//end identity()

	/**
	 * A dialect that is never asked to evaluate anything here.
	 *
	 * @return ConditionDialect The dialect.
	 */
	private function dialect(): ConditionDialect {
		return new ConditionDialect($this->createMock(CalculationEvaluatorInterface::class));
	}//end dialect()

	/**
	 * The transition document carries the object, previous, user and move.
	 *
	 * @return void
	 */
	public function testTheTransitionDocumentNamesTheSessionUser(): void {
		foreach ([true, false] as $signedIn) {
			[$session, $groups] = $this->identity($signedIn);
			$evaluator = new LifecycleConditionEvaluator(
				new LifecycleConditionReporting($this->createMock(IL10N::class), $this->createMock(LoggerInterface::class)),
				new LifecycleRuleCheck($this->dialect(), new LifecycleRuleCaller($session, $groups))
			);

			$user = ['uid' => '', 'groups' => []];
			if ($signedIn === true) {
				$user = ['uid' => 'alice', 'groups' => ['admin', 'zaak']];
			}

			$this->assertSame(
				[
					'object' => ['a' => 1],
					'previous' => ['a' => 0],
					'user' => $user,
					'transition' => ['action' => 'close', 'from' => 'open', 'to' => 'closed'],
				],
				$evaluator->document(['a' => 1], ['a' => 0], 'close', 'open', 'closed')
			);
		}
	}//end testTheTransitionDocumentNamesTheSessionUser()

	/**
	 * The state rule document carries the object, user and state.
	 *
	 * @return void
	 */
	public function testTheStateRuleDocumentNamesTheSessionUser(): void {
		foreach ([true, false] as $signedIn) {
			[$session, $groups] = $this->identity($signedIn);
			$resolver = new StateFieldRuleResolver($session, $groups, $this->dialect());

			$user = ['uid' => '', 'groups' => []];
			if ($signedIn === true) {
				$user = ['uid' => 'alice', 'groups' => ['admin', 'zaak']];
			}

			$this->assertSame(
				['object' => ['a' => 1], 'user' => $user, 'state' => 'open'],
				$resolver->document(['a' => 1], 'open')
			);
		}
	}//end testTheStateRuleDocumentNamesTheSessionUser()
}//end class
