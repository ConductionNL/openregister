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
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
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
	 * Build the service with a real TokenGrantSource wired in.
	 *
	 * newInstanceWithoutConstructor() leaves promoted properties UNINITIALISED —
	 * a parameter default is not a property default — so each one this test
	 * touches has to be set explicitly. That is also why runAsAnonymous() reads
	 * the source with `??` instead of `=== null`.
	 *
	 * @param TokenGrantSource $source The source to wire in.
	 *
	 * @return ObjectService The service under test.
	 */
	private function serviceWithGrantSource(TokenGrantSource $source): ObjectService {
		$reflection = new ReflectionClass(ObjectService::class);
		$service    = $reflection->newInstanceWithoutConstructor();

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->current);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->current = $user;
			}
		);

		foreach (['userSession' => $session, 'tokenGrantSource' => $source] as $name => $value) {
			$property = $reflection->getProperty($name);
			$property->setAccessible(true);
			$property->setValue($service, $value);
		}

		return $service;
	}//end serviceWithGrantSource()


	/**
	 * A grant is a ceiling on what ITS HOLDER may do, and inside this scope
	 * there is no holder. PermissionHandler consults the grant ahead of even the
	 * admin and owner bypasses, so leaving it bound would narrow the PUBLIC
	 * answer by the private state of a token — two callers, two answers, from an
	 * endpoint whose contract is that they get one (WOO-578, found reviewing
	 * #3855 after #3913 introduced TokenGrantSource).
	 *
	 * @return void
	 */
	public function testTheTokenGrantIsSuspendedInsideTheScope(): void {
		$source = new TokenGrantSource();
		$grant  = TokenGrant::fromStored(stored: ['read' => ['*']], tokenId: 'consumer-1');
		$source->bind(grant: $grant);

		$service = $this->serviceWithGrantSource($source);

		$inside = 'unset';
		$service->runAsAnonymous(
			static function () use (&$inside, $source): void {
				$inside = $source->current();
			}
		);

		$this->assertNull($inside, 'no grant may be in force inside an anonymous evaluation');
		$this->assertSame($grant, $source->current(), 'and the caller gets their grant back afterwards');
	}//end testTheTokenGrantIsSuspendedInsideTheScope()


	/**
	 * `bind(null)` is not the same state as never having bound: TokenGrantSource
	 * documents the difference as "a token with no grant is calling" versus "a
	 * person is calling". Suspending has to clear BOTH fields and restore both,
	 * or the scope silently rewrites which of those two a later reader sees.
	 *
	 * @return void
	 */
	public function testABoundTokenWithoutAGrantIsAlsoInvisibleInsideTheScope(): void {
		$source = new TokenGrantSource();
		$source->bind(grant: null);
		$this->assertTrue($source->isBound(), 'precondition: a machine principal bound, carrying no grant');

		$service = $this->serviceWithGrantSource($source);

		$inside = 'unset';
		$service->runAsAnonymous(
			static function () use (&$inside, $source): void {
				$inside = $source->isBound();
			}
		);

		$this->assertFalse($inside, 'inside the scope nothing is bound at all');
		$this->assertTrue($source->isBound(), 'and the binding is back afterwards');
	}//end testABoundTokenWithoutAGrantIsAlsoInvisibleInsideTheScope()


	/**
	 * Negative control. Without it the two tests above would also pass against a
	 * TokenGrantSource that simply never reports a grant, which would make them
	 * evidence of nothing.
	 *
	 * @return void
	 */
	public function testTheGrantIsVisibleOutsideTheScope(): void {
		$source = new TokenGrantSource();
		$grant  = TokenGrant::fromStored(stored: ['read' => ['*']], tokenId: 'consumer-1');
		$source->bind(grant: $grant);

		$this->serviceWithGrantSource($source);

		$this->assertSame($grant, $source->current(), 'the recorder fires when nothing suspends it');
		$this->assertTrue($source->isBound());
	}//end testTheGrantIsVisibleOutsideTheScope()


	/**
	 * A throw inside the callable must not leave the request without its grant —
	 * the restore has to sit in a `finally`, as it does for the subject.
	 *
	 * @return void
	 */
	public function testTheTokenGrantIsRestoredWhenTheCallableThrows(): void {
		$source = new TokenGrantSource();
		$grant  = TokenGrant::fromStored(stored: ['read' => ['*']], tokenId: 'consumer-1');
		$source->bind(grant: $grant);

		$service = $this->serviceWithGrantSource($source);

		try {
			$service->runAsAnonymous(
				static function (): void {
					throw new RuntimeException('the read failed');
				}
			);
			$this->fail('Expected the exception to propagate.');
		} catch (RuntimeException $e) {
			$this->assertSame('the read failed', $e->getMessage());
		}

		$this->assertSame($grant, $source->current(), 'the grant must be restored on a throw');
		$this->assertTrue($source->isBound());
	}//end testTheTokenGrantIsRestoredWhenTheCallableThrows()


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
