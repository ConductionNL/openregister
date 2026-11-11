<?php

/**
 * RateLimitMiddlewareBehaviourTest — lockout, identity resolution and fail-open paths.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\Middleware\AuthRateLimitExceededException;
use OCA\OpenRegister\Middleware\InboundCall;
use OCA\OpenRegister\Middleware\RateLimitMiddleware;
use OCA\OpenRegister\Service\FailedAttempt;
use OCA\OpenRegister\Service\InboundAuthRateLimitInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Middleware\RateLimitMiddleware
 * @uses   \OCA\OpenRegister\Middleware\AuthRateLimitExceededException
 */
class RateLimitMiddlewareBehaviourTest extends TestCase {

	private InboundAuthRateLimitInterface&MockObject $security;

	private LoggerInterface&MockObject $logger;

	private IUserSession&MockObject $session;

	private RateLimitProbeReflector&MockObject $reflector;

	protected function setUp(): void {
		$this->security = $this->createMock(InboundAuthRateLimitInterface::class);
		$this->security->method('getTrustedClientIpAddress')->willReturn('10.0.0.1');
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->reflector = $this->createMock(RateLimitProbeReflector::class);
	}

	private function middleware(string $authHeader = ''): RateLimitMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Authorization' ? $authHeader : '')
		);

		return new RateLimitMiddleware(
			new InboundCall($request, $this->session, $this->reflector, $this->logger),
			$this->security
		);
	}

	private function controller(): Controller {
		return $this->createMock(Controller::class);
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	public function testALockedOutCallerIsRefusedWithTheServiceReason(): void {
		$this->session->method('getUser')->willReturn($this->user('alice'));
		$this->security->expects($this->once())->method('checkAuthRateLimit')
			->with('alice', '10.0.0.1')
			->willReturn(['allowed' => false, 'reason' => 'slow down', 'lockout_until' => 77]);

		try {
			$this->middleware()->beforeController($this->controller(), 'index');
			$this->fail('expected lockout');
		} catch (AuthRateLimitExceededException $e) {
			$this->assertSame('slow down', $e->getMessage());
			$this->assertSame(77, $e->getLockoutUntil());
		}
	}

	public function testALockoutWithoutReasonUsesTheDefaultMessage(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->security->method('checkAuthRateLimit')->willReturn(['allowed' => false]);

		try {
			$this->middleware()->beforeController($this->controller(), 'index');
			$this->fail('expected lockout');
		} catch (AuthRateLimitExceededException $e) {
			$this->assertSame('Too many failed authentication attempts.', $e->getMessage());
			$this->assertSame(0, $e->getLockoutUntil());
		}
	}

	public function testAnAllowedCallerPassesWithoutWarning(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->security->expects($this->once())->method('checkAuthRateLimit')
			->with('', '10.0.0.1')->willReturn(['allowed' => true]);
		$this->logger->expects($this->never())->method('warning');

		$this->middleware()->beforeController($this->controller(), 'index');
	}

	public function testAPublicPageSkipsThePrecheck(): void {
		$controller = $this->controller();
		$this->reflector->expects($this->once())->method('reflect')->with($controller, 'index');
		$this->reflector->method('hasAnnotation')->with('PublicPage')->willReturn(true);
		$this->security->expects($this->never())->method('checkAuthRateLimit');

		$this->middleware()->beforeController($controller, 'index');
	}

	public function testAReflectorFailureTreatsThePageAsNonPublic(): void {
		$this->reflector->method('reflect')->willThrowException(new RuntimeException('no method'));
		$this->session->method('getUser')->willReturn(null);
		$this->security->expects($this->once())->method('checkAuthRateLimit')->willReturn([]);

		$this->middleware()->beforeController($this->controller(), 'index');
	}

	public function testAPrecheckFailureFailsOpenWithAWarning(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->security->method('checkAuthRateLimit')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')
			->with('[RateLimitMiddleware] Rate-limit precheck failed open: db down', $this->anything());

		$this->middleware()->beforeController($this->controller(), 'index');
	}

	public function testBasicAuthUsernameIsTheIdentityWhenNoSessionUser(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->security->expects($this->once())->method('checkAuthRateLimit')
			->with('bob', '10.0.0.1')->willReturn([]);

		$this->middleware('Basic ' . base64_encode('bob:secret:with:colons'))->beforeController($this->controller(), 'index');
	}

	public function testABasicHeaderWithoutColonGivesAnEmptyIdentity(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->security->expects($this->once())->method('checkAuthRateLimit')
			->with('', '10.0.0.1')->willReturn([]);

		$this->middleware('Basic ' . base64_encode('nocolon'))->beforeController($this->controller(), 'index');
	}

	public function testABearerHeaderGivesAnEmptyIdentity(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->security->expects($this->once())->method('checkAuthRateLimit')
			->with('', '10.0.0.1')->willReturn([]);

		$this->middleware('Bearer abc')->beforeController($this->controller(), 'index');
	}

	public function testASessionFailureGivesAnEmptyIdentity(): void {
		$this->session->method('getUser')->willThrowException(new RuntimeException('session broken'));
		$this->security->expects($this->once())->method('checkAuthRateLimit')
			->with('', '10.0.0.1')->willReturn([]);

		$this->middleware('Basic ' . base64_encode('bob:x'))->beforeController($this->controller(), 'index');
	}

	public function testASuccessfulRequestIsRecordedForTheSessionUser(): void {
		$this->session->method('getUser')->willReturn($this->user('carol'));
		$this->security->expects($this->once())->method('recordSuccessfulAuth')->with('carol', '10.0.0.1');
		$response = new Response();

		$this->assertSame($response, $this->middleware()->afterController($this->controller(), 'index', $response));
	}

	public function testAnAnonymousSuccessRecordsNothing(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->security->expects($this->never())->method('recordSuccessfulAuth');

		$this->middleware()->afterController($this->controller(), 'index', new Response());
	}

	public function testARecordSuccessFailureFailsOpen(): void {
		$this->session->method('getUser')->willReturn($this->user('carol'));
		$this->security->method('recordSuccessfulAuth')->willThrowException(new RuntimeException('nope'));
		$this->logger->expects($this->once())->method('debug')
			->with('[RateLimitMiddleware] recordSuccessfulAuth failed open: nope', $this->anything());
		$response = new Response();

		$this->assertSame($response, $this->middleware()->afterController($this->controller(), 'index', $response));
	}

	public function testALockoutExceptionBecomesA429WithRetryAfter(): void {
		$response = $this->middleware()->afterException(
			$this->controller(),
			'index',
			new AuthRateLimitExceededException('locked', 0)
		);

		$this->assertSame(429, $response->getStatus());
		$this->assertSame(['error' => 'locked', 'lockout_until' => 0], $response->getData());
		$this->assertSame('1', $response->getHeaders()['Retry-After']);
	}

	public function testANotLoggedInExceptionIsCountedAndRethrown(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->security->expects($this->once())->method('recordFailedAuthAttempt')
			->with(new FailedAttempt('dave', '10.0.0.1', 'inbound_auth_failed'));
		$exception = new NotLoggedInException('not logged in');

		try {
			$this->middleware('Basic ' . base64_encode('dave:pw'))->afterException($this->controller(), 'index', $exception);
			$this->fail('expected rethrow');
		} catch (NotLoggedInException $e) {
			$this->assertSame($exception, $e);
		}
	}

	public function testASecurityExceptionWithA401IsCounted(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->security->expects($this->once())->method('recordFailedAuthAttempt');
		$named = new RateLimitProbeSecurityException('denied', 401);

		$this->expectExceptionObject($named);
		$this->middleware()->afterException($this->controller(), 'index', $named);
	}

	public function testANonAuthExceptionIsNotCounted(): void {
		$this->security->expects($this->never())->method('recordFailedAuthAttempt');
		$exception = new RuntimeException('plain', 401);

		$this->expectExceptionObject($exception);
		$this->middleware()->afterException($this->controller(), 'index', $exception);
	}

	public function testAnAuthFailureOnAPublicPageIsNotCounted(): void {
		$this->reflector->method('hasAnnotation')->willReturn(true);
		$this->security->expects($this->never())->method('recordFailedAuthAttempt');
		$exception = new NotLoggedInException('not logged in');

		$this->expectExceptionObject($exception);
		$this->middleware()->afterException($this->controller(), 'index', $exception);
	}

	public function testACountingFailureStillRethrowsTheOriginal(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->security->method('recordFailedAuthAttempt')->willThrowException(new RuntimeException('db'));
		$this->logger->expects($this->once())->method('warning')
			->with('[RateLimitMiddleware] recordFailedAuthAttempt failed open: db', $this->anything());
		$exception = new NotLoggedInException('not logged in');

		$this->expectExceptionObject($exception);
		$this->middleware()->afterException($this->controller(), 'index', $exception);
	}
}

/**
 * An exception whose short name marks it as security-related.
 */
class RateLimitProbeSecurityException extends \Exception {
}

/**
 * The reflector as Nextcloud implements it: the interface plus reflect().
 */
abstract class RateLimitProbeReflector implements IControllerMethodReflector {

	/**
	 * Reflect a controller method.
	 *
	 * @param object $object Controller.
	 * @param string $method Method name.
	 *
	 * @return void
	 */
	abstract public function reflect($object, string $method);
}

/**
 * Stand-in carrying the short name Nextcloud's not-logged-in exception has.
 */
class NotLoggedInException extends \Exception {
}
