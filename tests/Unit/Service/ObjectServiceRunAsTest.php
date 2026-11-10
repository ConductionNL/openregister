<?php

/**
 * Unit coverage for ObjectService::runAs(), the scoped acting-user wrapper.
 *
 * The read side of the query layer has no acting-user parameter: `findAll()`
 * and everything beneath it resolve the subject from `IUserSession`, and the
 * two handlers that build the RBAC and organisation predicates read
 * `getUser()` directly at roughly a dozen points. A caller holding an explicit
 * user therefore has nowhere to put it, which is how
 * `ObjectReadNode::read()` came to declare and document an `IUser $owner` it
 * never used.
 *
 * `runAs()` sets the subject for the duration of one callable and restores it
 * afterwards, mirroring the pattern `ActorForwardedJob` already uses so a cron
 * process never carries one job's identity into the next.
 *
 * ObjectService takes ~40 collaborators, so these construct it without its
 * constructor and inject only the session it uses — the method under test
 * touches nothing else.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Locks the scoping and release guarantees of ObjectService::runAs().
 */
class ObjectServiceRunAsTest extends TestCase {

	private ObjectService $service;

	/**
	 * The session double's current user.
	 *
	 * @var IUser|null
	 */
	private ?IUser $current = null;

	protected function setUp(): void {
		parent::setUp();

		$this->current = null;

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->current);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->current = $user;
			}
		);

		// `setUser()` ALSO writes `user_id` into the PHP session, and a session
		// is started on every request but status.php. A `finally` does not run
		// on a fatal or an exit(), so using it here would leave the acting
		// identity persisted in the caller's session. Asserting it is never
		// called is the whole point of this double — a regression to setUser()
		// would otherwise pass every other test in this class unchanged.
		$session->expects($this->never())->method('setUser');

		$reflection = new ReflectionClass(ObjectService::class);
		$this->service = $reflection->newInstanceWithoutConstructor();

		$property = $reflection->getProperty('userSession');
		$property->setAccessible(true);
		$property->setValue($this->service, $session);
	}//end setUp()

	/**
	 * Build a named user double.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser The double.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * The callable runs with the named user as the session subject.
	 *
	 * @return void
	 */
	public function testTheCallableRunsAsTheNamedUser(): void {
		$seen = null;

		$this->service->runAs(
			$this->user('alice'),
			function () use (&$seen): void {
				$seen = $this->current?->getUID();
			}
		);

		$this->assertSame('alice', $seen);
	}//end testTheCallableRunsAsTheNamedUser()

	/**
	 * The callable's return value is passed through.
	 *
	 * @return void
	 */
	public function testTheReturnValueIsPassedThrough(): void {
		$result = $this->service->runAs($this->user('alice'), static fn (): string => 'answer');

		$this->assertSame('answer', $result);
	}//end testTheReturnValueIsPassedThrough()

	/**
	 * The previous subject is restored afterwards.
	 *
	 * @return void
	 */
	public function testThePreviousSubjectIsRestored(): void {
		$this->current = $this->user('bob');

		$this->service->runAs($this->user('alice'), static fn (): bool => true);

		$this->assertSame('bob', $this->current?->getUID());
	}//end testThePreviousSubjectIsRestored()

	/**
	 * An empty session stays empty afterwards.
	 *
	 * This is the case that matters for cron: the worker has no session, and
	 * must not acquire one because a flow ran.
	 *
	 * @return void
	 */
	public function testAnEmptySessionIsLeftEmpty(): void {
		$this->service->runAs($this->user('alice'), static fn (): bool => true);

		$this->assertNull($this->current);
	}//end testAnEmptySessionIsLeftEmpty()

	/**
	 * The subject is restored even when the callable throws.
	 *
	 * Without the `finally` a failed read would leave a long-lived process
	 * impersonating the last flow owner — a privilege leak that only shows up
	 * under load.
	 *
	 * @return void
	 */
	public function testTheSubjectIsRestoredWhenTheCallableThrows(): void {
		$this->current = $this->user('bob');

		try {
			$this->service->runAs(
				$this->user('alice'),
				static function (): void {
					throw new RuntimeException('the read failed');
				}
			);
			$this->fail('Expected the exception to propagate.');
		} catch (RuntimeException $e) {
			$this->assertSame('the read failed', $e->getMessage());
		}

		$this->assertSame('bob', $this->current?->getUID(), 'the subject must be restored on a throw');
	}//end testTheSubjectIsRestoredWhenTheCallableThrows()

	/**
	 * Nested scopes compose, innermost first, unwinding correctly.
	 *
	 * Restoring the PREVIOUS subject rather than clearing is what makes this
	 * work.
	 *
	 * @return void
	 */
	public function testNestedScopesCompose(): void {
		$inner = null;
		$outer = null;

		$this->service->runAs(
			$this->user('alice'),
			function () use (&$inner, &$outer): void {
				$this->service->runAs(
					$this->user('carol'),
					function () use (&$inner): void {
						$inner = $this->current?->getUID();
					}
				);

				$outer = $this->current?->getUID();
			}
		);

		$this->assertSame('carol', $inner);
		$this->assertSame('alice', $outer, 'the outer scope must survive the inner one');
		$this->assertNull($this->current);
	}//end testNestedScopesCompose()

	/**
	 * The acting identity is never written into the session.
	 *
	 * The session double in setUp() fails the test if `setUser()` is called at
	 * all. This test states the guarantee by name so it reads as a requirement
	 * rather than as an incidental mock expectation, and so a failure points at
	 * the reason rather than at an unexpected-invocation message.
	 *
	 * Why it matters: `setUser()` persists `user_id` into the PHP session, a
	 * session is started on every request but status.php, and the `finally` that
	 * restores the subject does not run on a fatal or an `exit()`. A scope built
	 * on `setUser()` therefore leaves the caller's session authenticated as the
	 * acted-as user, and the response carries a cookie for it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegated-identity/spec.md
	 */
	public function testTheActingIdentityIsNeverWrittenToTheSession(): void {
		$this->service->runAs($this->user('alice'), static fn (): bool => true);

		try {
			$this->service->runAs(
				$this->user('alice'),
				static function (): void {
					throw new RuntimeException('boom');
				}
			);
		} catch (RuntimeException) {
			// The throwing path must not persist either.
		}

		$this->assertNull(
			$this->current,
			'no acting identity may survive the scopes that established it'
		);
	}//end testTheActingIdentityIsNeverWrittenToTheSession()
}//end class
