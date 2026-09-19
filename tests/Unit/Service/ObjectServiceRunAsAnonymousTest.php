<?php

/**
 * Unit tests for ObjectService::runAsAnonymous().
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\AnonymousEvaluationContext;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * runAsAnonymous() clears the session subject AND opens the anonymous scope for
 * the duration of the callable, then restores what it found (WOO-578).
 */
class ObjectServiceRunAsAnonymousTest extends TestCase {

	private ObjectService $service;

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
		// Same guard as ObjectServiceRunAsTest: setUser() would persist the
		// cleared identity into the caller's PHP session.
		$session->expects($this->never())->method('setUser');

		$reflection = new ReflectionClass(ObjectService::class);
		$this->service = $reflection->newInstanceWithoutConstructor();
		$property = $reflection->getProperty('userSession');
		$property->setAccessible(true);
		$property->setValue($this->service, $session);
	}//end setUp()


	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()


	public function testTheCallableSeesNoUserEvenWhenAnAdminIsSignedIn(): void {
		$this->current = $this->user('admin');
		$seen = 'unset';
		$this->service->runAsAnonymous(
			function () use (&$seen): void {
				$seen = $this->current;
			}
		);
		$this->assertNull($seen, 'the subject must be cleared inside the scope');
	}//end testTheCallableSeesNoUserEvenWhenAnAdminIsSignedIn()


	public function testTheAnonymousScopeIsOpenInsideAndClosedAfter(): void {
		$inside = null;
		$this->service->runAsAnonymous(
			function () use (&$inside): void {
				$inside = AnonymousEvaluationContext::isActive();
			}
		);
		$this->assertTrue($inside);
		$this->assertFalse(AnonymousEvaluationContext::isActive());
	}//end testTheAnonymousScopeIsOpenInsideAndClosedAfter()


	public function testSystemTrustIsWithheldInsideTheScope(): void {
		$inside = null;
		SystemOperationContext::run(
			function () use (&$inside): void {
				$this->service->runAsAnonymous(
					function () use (&$inside): void {
						$inside = SystemOperationContext::isActive();
					}
				);
			}
		);
		$this->assertFalse($inside, 'an anonymous evaluation is never the system');
	}//end testSystemTrustIsWithheldInsideTheScope()


	public function testTheReturnValueIsPassedThrough(): void {
		$result = $this->service->runAsAnonymous(static fn (): string => 'answer');
		$this->assertSame('answer', $result);
	}//end testTheReturnValueIsPassedThrough()


	public function testThePreviousSubjectIsRestored(): void {
		$this->current = $this->user('bob');
		$this->service->runAsAnonymous(static fn (): bool => true);
		$this->assertSame('bob', $this->current?->getUID());
	}//end testThePreviousSubjectIsRestored()


	public function testTheSubjectAndScopeAreRestoredWhenTheCallableThrows(): void {
		$this->current = $this->user('bob');
		try {
			$this->service->runAsAnonymous(
				static function (): void {
					throw new RuntimeException('the read failed');
				}
			);
			$this->fail('Expected the exception to propagate.');
		} catch (RuntimeException $e) {
			$this->assertSame('the read failed', $e->getMessage());
		}
		$this->assertSame('bob', $this->current?->getUID(), 'the subject must be restored on a throw');
		$this->assertFalse(AnonymousEvaluationContext::isActive(), 'the scope must be released on a throw');
	}//end testTheSubjectAndScopeAreRestoredWhenTheCallableThrows()


	/**
	 * THE ONE THAT MATTERS ON A REAL REQUEST.
	 *
	 * Core's `Session::getUser()` treats a null `activeUser` as "not resolved
	 * yet" and falls back to the `user_id` in the PHP session, so clearing the
	 * volatile user does NOT make the caller anonymous while a session exists —
	 * the next read hands back the same signed-in admin. A plain
	 * `createMock(IUserSession::class)` cannot catch that, because it models
	 * `setUser()` semantics: set null, get null.
	 *
	 * This double reproduces the fallback. Without incognito mode the read
	 * inside the scope returns the admin and this test fails, which is exactly
	 * what shipped before the review caught it.
	 */
	public function testTheSubjectIsGoneEvenWhileThePhpSessionStillNamesAUser(): void {
		$admin = $this->user('admin');
		// The memoised copy core keeps in Session::$activeUser.
		$active = $admin;

		$session = $this->createMock(IUserSession::class);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user) use (&$active): void {
				$active = $user;
			}
		);
		$session->method('getUser')->willReturnCallback(
			function () use (&$active, $admin): ?IUser {
				// Verbatim shape of Session::getUser(): incognito first, then the
				// "null means unresolved" re-read of user_id.
				if (\OC_User::isIncognitoMode() === true) {
					return null;
				}

				if ($active === null) {
					$active = $admin;
				}

				return $active;
			}
		);

		$reflection = new ReflectionClass(ObjectService::class);
		$service = $reflection->newInstanceWithoutConstructor();
		$property = $reflection->getProperty('userSession');
		$property->setAccessible(true);
		$property->setValue($service, $session);

		$seen = 'unset';
		$service->runAsAnonymous(
			static function () use (&$seen, $session): void {
				$seen = $session->getUser();
			}
		);

		$this->assertNull($seen, 'the session still names a user — the scope must still read as nobody');
		$this->assertSame($admin, $session->getUser(), 'and the caller gets their session back afterwards');
		$this->assertFalse(\OC_User::isIncognitoMode(), 'incognito mode must not leak past the scope');
	}

	/**
	 * Nesting inside a genuinely incognito request must leave it incognito,
	 * rather than switching the caller's own mode off on the way out.
	 */
	public function testAnIncognitoCallerStaysIncognitoAfterwards(): void {
		\OC_User::setIncognitoMode(true);
		try {
			$this->service->runAsAnonymous(static fn (): bool => true);
			$this->assertTrue(\OC_User::isIncognitoMode(), 'the previous state is restored, not cleared');
		} finally {
			\OC_User::setIncognitoMode(false);
		}
	}

	public function testNestingInsideRunAsRestoresTheNamedUser(): void {
		$inner = 'unset';
		$outer = null;
		$this->service->runAs(
			$this->user('alice'),
			function () use (&$inner, &$outer): void {
				$this->service->runAsAnonymous(
					function () use (&$inner): void {
						$inner = $this->current;
					}
				);
				$outer = $this->current?->getUID();
			}
		);
		$this->assertNull($inner);
		$this->assertSame('alice', $outer, 'the named scope must survive the anonymous one');
		$this->assertNull($this->current);
	}//end testNestingInsideRunAsRestoresTheNamedUser()
}//end class
