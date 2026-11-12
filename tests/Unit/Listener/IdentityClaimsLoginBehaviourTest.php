<?php

/**
 * Sign-in derives access from asserted identity claims, and never blocks a sign-in.
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

use OCA\OpenRegister\Event\IdentityClaimsCollectingEvent;
use OCA\OpenRegister\Listener\IdentityClaimsLoginListener;
use OCA\OpenRegister\Service\Rbac\DerivedGrantLoginInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\User\Events\UserLoggedInEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Each branch of the sign-in derivation: no rules, no claims, claims, failure, foreign event.
 */
class IdentityClaimsLoginBehaviourTest extends TestCase {
	private function signIn(string $uid='ann'): UserLoggedInEvent {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return new UserLoggedInEvent($user, $uid, null, false);
	}

	/**
	 * A dispatcher whose listeners assert the given claims.
	 *
	 * @param array<string, mixed> $claims The claims to assert.
	 *
	 * @return IEventDispatcher The dispatcher double.
	 */
	private function asserting(array $claims): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped')
			->with($this->callback(function (Event $event) use ($claims): bool {
				if ($event instanceof IdentityClaimsCollectingEvent === false || $event->getUserId() !== 'ann') {
					return false;
				}
				$event->assert($claims);
				return true;
			}));
		return $dispatcher;
	}

	public function testNoRulesMeansNoDispatchAndNoChange(): void {
		$store = $this->createMock(DerivedGrantLoginInterface::class);
		$store->method('rules')->willReturn([]);
		$store->expects($this->never())->method('apply');
		$store->expects($this->never())->method('forget');
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');

		(new IdentityClaimsLoginListener($store, $dispatcher, $this->createMock(LoggerInterface::class)))->handle($this->signIn());
	}

	public function testASignInAssertingNothingForgetsTheDerivedAccess(): void {
		$store = $this->createMock(DerivedGrantLoginInterface::class);
		$store->method('rules')->willReturn([['claim' => 'department']]);
		$store->expects($this->once())->method('forget')->with('ann');
		$store->expects($this->never())->method('apply');

		(new IdentityClaimsLoginListener($store, $this->asserting([]), $this->createMock(LoggerInterface::class)))->handle($this->signIn());
	}

	public function testAssertedClaimsAreAppliedAndLoggedWithTheirNamesAndGrantCount(): void {
		$claims = ['department' => 'finance', 'role' => 'clerk'];
		$store  = $this->createMock(DerivedGrantLoginInterface::class);
		$store->method('rules')->willReturn([['claim' => 'department']]);
		$store->expects($this->once())->method('apply')->with('ann', $claims)->willReturn(['g1', 'g2', 'g3']);
		$store->expects($this->never())->method('forget');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			$this->stringContains('Derived access'),
			$this->callback(fn (array $context): bool => $context['userId'] === 'ann'
				&& $context['claims'] === ['department', 'role']
				&& $context['grants'] === 3)
		);

		(new IdentityClaimsLoginListener($store, $this->asserting($claims), $logger))->handle($this->signIn());
	}

	public function testAFailingStoreIsLoggedAndDoesNotEscapeTheSignIn(): void {
		$store = $this->createMock(DerivedGrantLoginInterface::class);
		$store->method('rules')->willThrowException(new RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			$this->stringContains('Could not derive access'),
			$this->callback(fn (array $context): bool => $context['userId'] === 'ann' && $context['error'] === 'db down')
		);

		(new IdentityClaimsLoginListener($store, $this->createMock(IEventDispatcher::class), $logger))->handle($this->signIn());
	}

	public function testAnEventOtherThanASignInIsIgnored(): void {
		$store = $this->createMock(DerivedGrantLoginInterface::class);
		$store->expects($this->never())->method('rules');

		(new IdentityClaimsLoginListener($store, $this->createMock(IEventDispatcher::class), $this->createMock(LoggerInterface::class)))->handle(new Event());
	}
}
