<?php

/**
 * Golden test for the access middlewares: tenant status and quota, the
 * failed-auth rate limit, the per-caller bounds and the public CORS reflection.
 *
 * Pins what each middleware allows and what it refuses, so a refactor of
 * their shape can be shown to leave both sides unchanged.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use Exception;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Middleware\ApiCallerMiddleware;
use OCA\OpenRegister\Middleware\AuthRateLimitExceededException;
use OCA\OpenRegister\Middleware\Exception\CallerRefusedException;
use OCA\OpenRegister\Middleware\InboundCall;
use OCA\OpenRegister\Middleware\PublicApiCorsMiddleware;
use OCA\OpenRegister\Middleware\RateLimitMiddleware;
use OCA\OpenRegister\Middleware\Support\ApiCallerServices;
use OCA\OpenRegister\Middleware\TenantQuotaExceededException;
use OCA\OpenRegister\Middleware\TenantQuotaMiddleware;
use OCA\OpenRegister\Middleware\TenantStatusException;
use OCA\OpenRegister\Service\ApiCaller\ApiCallRecorder;
use OCA\OpenRegister\Service\ApiCaller\CallerPolicy;
use OCA\OpenRegister\Service\ApiCaller\CallerRateLimiter;
use OCA\OpenRegister\Service\ApiVersion\ApiVersion;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionLifecycle;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiation;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiator;
use OCA\OpenRegister\Service\FailedAttempt;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\SecurityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\ApiCaller\ApiCallObservation;

/**
 * Allowed and refused outcomes of the access middlewares.
 */
final class AccessMiddlewareGoldenTest extends TestCase {

	/**
	 * A tenant-quota middleware over the given user and active organisation.
	 *
	 * @param IUser|null        $user   Signed-in user.
	 * @param Organisation|null $org    Active organisation.
	 * @param bool              $admin  Whether the user is an admin.
	 * @param LoggerInterface|null $logger Logger double.
	 *
	 * @return TenantQuotaMiddleware
	 */
	private function tenant(?IUser $user, ?Organisation $org, bool $admin=false, ?LoggerInterface $logger=null): TenantQuotaMiddleware {
		$orgs = $this->createMock(OrganisationServiceInterface::class);
		$orgs->method('getActiveOrganisation')->willReturn($org);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new TenantQuotaMiddleware($orgs, $this->inboundCall($this->createMock(IRequest::class), $session, $logger), $groups);
	}

	/**
	 * An organisation in the given status.
	 *
	 * @param string|null $status Status.
	 *
	 * @return Organisation
	 */
	private function org(?string $status): Organisation {
		$org = new Organisation();
		if ($status !== null) {
			$org->setStatus($status);
		}

		$org->setUuid('org-1');
		return $org;
	}

	/**
	 * A user double.
	 *
	 * @param string $uid Uid.
	 *
	 * @return IUser&MockObject
	 */
	private function user(string $uid='alice'): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	/**
	 * Status of each refused organisation state, and its message.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refusedStatuses(): array {
		return [
			'suspended' => ['suspended', 'Organisation is suspended'],
			'deprovisioning' => ['deprovisioning', 'Organisation is being deprovisioned'],
			'retained' => ['retained', 'Organisation access has ended and its data is retained'],
			'provisioning, not admin' => ['provisioning', 'Organisation is being provisioned'],
		];
	}

	/**
	 * @dataProvider refusedStatuses
	 */
	public function testTenantRefusesTheseStatuses(string $status, string $message): void {
		try {
			$this->tenant($this->user(), $this->org($status))->beforeController('C', 'm');
			$this->fail('expected a refusal');
		} catch (TenantStatusException $e) {
			$this->assertSame($message, $e->getMessage());
			$this->assertSame($status, $e->getStatus());
			$this->assertSame(403, $e->getCode());
		}
	}

	/**
	 * Allowed tenant situations.
	 *
	 * @return array<string, array{0: bool, 1: string|null, 2: bool, 3: bool}>
	 */
	public static function allowedTenants(): array {
		return [
			'no user' => [false, 'suspended', false, true],
			'no organisation' => [true, null, false, false],
			'active' => [true, 'active', false, true],
			'no status' => [true, null, false, true],
			'provisioning admin' => [true, 'provisioning', true, true],
		];
	}

	/**
	 * @dataProvider allowedTenants
	 */
	public function testTenantAllowsThese(bool $signedIn, ?string $status, bool $admin, bool $hasOrg): void {
		$org = null;
		if ($hasOrg === true) {
			$org = $this->org($status);
		}

		$user = null;
		if ($signedIn === true) {
			$user = $this->user();
		}

		$refusedStatus = null;
		try {
			$this->tenant($user, $org, $admin)->beforeController('C', 'm');
		} catch (TenantStatusException $e) {
			$refusedStatus = $e->getStatus();
		}

		// The pair is testTenantRefusesTheseStatuses: the same middleware, the
		// deciding status (or the admin flag for provisioning) flipped.
		$this->assertNull($refusedStatus, 'The tenant middleware refused a request it must let through.');
	}

	public function testTenantLetsARequestThroughWhenTheOrganisationCannotLoad(): void {
		$orgs = $this->createMock(OrganisationServiceInterface::class);
		$orgs->method('getActiveOrganisation')->willThrowException(new RuntimeException('db gone'));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user());
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')->with($this->stringStartsWith('[TenantQuotaMiddleware] Could not load organisation'));
		$mw = new TenantQuotaMiddleware($orgs, $this->inboundCall($this->createMock(IRequest::class), $session, $logger), $this->createMock(IGroupManager::class));

		$mw->beforeController('C', 'm');
		$response = new JSONResponse(['a' => 1]);
		$this->assertSame($response, $mw->afterController('C', 'm', $response));
	}

	public function testTenantAfterControllerReturnsTheSameResponse(): void {
		$response = new JSONResponse(['a' => 1]);
		$this->assertSame($response, $this->tenant($this->user(), $this->org('active'))->afterController('C', 'm', $response));
		$this->assertSame($response, $this->tenant($this->user(), null)->afterController('C', 'm', $response));
		$plain = new Response();
		$this->assertSame($plain, $this->tenant($this->user(), new Organisation())->afterController('C', 'm', $plain));
	}

	public function testTenantRendersAStatusRefusal(): void {
		$response = $this->tenant(null, null)->afterException('C', 'm', new TenantStatusException('Organisation is suspended', 'suspended', 403));
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'Organisation is suspended', 'status' => 'suspended'], $response->getData());
	}

	public function testTenantRendersAQuotaRefusal(): void {
		$exception = new TenantQuotaExceededException('Request quota exceeded', 100, '2030-01-01T00:00:00+00:00', 42);
		$response = $this->tenant(null, null)->afterException('C', 'm', $exception);
		$this->assertSame(429, $response->getStatus());
		$this->assertSame(['error' => 'Request quota exceeded', 'quota' => 100, 'resetAt' => '2030-01-01T00:00:00+00:00'], $response->getData());
		$this->assertSame('42', $response->getHeaders()['Retry-After']);
	}

	public function testTenantRethrowsAnythingElse(): void {
		$exception = new Exception('other');
		try {
			$this->tenant(null, null)->afterException('C', 'm', $exception);
			$this->fail('expected a rethrow');
		} catch (Exception $e) {
			$this->assertSame($exception, $e);
		}
	}

	/**
	 * A rate-limit middleware.
	 *
	 * @param SecurityService&MockObject $security Security double.
	 * @param bool                       $public   Whether the method is a public page.
	 * @param IUser|null                 $user     Signed-in user.
	 * @param string                     $auth     Authorization header.
	 *
	 * @return RateLimitMiddleware
	 */
	private function limiter(SecurityService $security, bool $public=false, ?IUser $user=null, string $auth='', ?LoggerInterface $logger=null): RateLimitMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnMap([['Authorization', $auth]]);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$security->method('getTrustedClientIpAddress')->willReturn('10.0.0.1');

		return new RateLimitMiddleware(new InboundCall($request, $session, $this->reflector($public), $logger ?? $this->createMock(LoggerInterface::class)), $security);
	}

	/**
	 * The inbound call the screening middlewares read.
	 */
	private function inboundCall(IRequest $request, IUserSession $session, ?LoggerInterface $logger=null, ?IControllerMethodReflector $reflector=null): InboundCall {
		return new InboundCall(
			$request,
			$session,
			$reflector ?? $this->createMock(IControllerMethodReflector::class),
			$logger ?? $this->createMock(LoggerInterface::class)
		);
	}


	/**
	 * A reflector that, like the container's, can reflect a method.
	 *
	 * @param bool $public Whether the method is a public page.
	 * @param bool $throws Whether reflecting fails.
	 *
	 * @return IControllerMethodReflector
	 */
	private function reflector(bool $public, bool $throws=false): IControllerMethodReflector {
		return new class($public, $throws) implements IControllerMethodReflector {
			public function __construct(private bool $public, private bool $throws) {
			}

			public function reflect(object $object, string $method): void {
				if ($this->throws === true) {
					throw new RuntimeException('cannot reflect');
				}
			}

			public function getType(string $parameter) {
				return null;
			}

			public function getParameters(): array {
				return [];
			}

			public function hasAnnotation(string $name): bool {
				return $name === 'PublicPage' && $this->public === true;
			}

			public function hasAnnotationOrAttribute(?string $annotationName, string $attributeClass): bool {
				return false;
			}
		};
	}

	/**
	 * A controller double.
	 *
	 * @return Controller
	 */
	private function controller(): Controller {
		return $this->createMock(Controller::class);
	}

	public function testRateLimitSkipsAPublicPage(): void {
		$security = $this->createMock(SecurityService::class);
		$security->expects($this->never())->method('checkAuthRateLimit');
		$this->limiter($security, true)->beforeController($this->controller(), 'm');
	}

	public function testRateLimitRefusesALockedOutIdentity(): void {
		$security = $this->createMock(SecurityService::class);
		$security->expects($this->once())->method('checkAuthRateLimit')->with('alice', '10.0.0.1')
			->willReturn(['allowed' => false, 'lockout_until' => 77]);
		try {
			$this->limiter($security, false, $this->user())->beforeController($this->controller(), 'm');
			$this->fail('expected a lockout');
		} catch (AuthRateLimitExceededException $e) {
			$this->assertSame('Too many failed authentication attempts.', $e->getMessage());
			$this->assertSame(77, $e->getLockoutUntil());
		}
	}

	public function testRateLimitRefusalCarriesTheReason(): void {
		$security = $this->createMock(SecurityService::class);
		$security->method('checkAuthRateLimit')->willReturn(['allowed' => false, 'reason' => 'locked', 'lockout_until' => 5]);
		$this->expectException(AuthRateLimitExceededException::class);
		$this->expectExceptionMessage('locked');
		$this->limiter($security)->beforeController($this->controller(), 'm');
	}

	/**
	 * Identity the limiter is keyed on, per Authorization header.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function identities(): array {
		return [
			'basic' => ['Basic ' . base64_encode('bob:secret'), 'bob'],
			'basic no colon' => ['Basic ' . base64_encode('bob'), ''],
			'bearer' => ['Bearer abc', ''],
			'none' => ['', ''],
			'bad base64' => ['Basic ***', ''],
		];
	}

	/**
	 * @dataProvider identities
	 */
	public function testRateLimitKeysOnTheAttemptedIdentity(string $header, string $identity): void {
		$security = $this->createMock(SecurityService::class);
		$security->expects($this->once())->method('checkAuthRateLimit')->with($identity, '10.0.0.1')->willReturn(['allowed' => true]);
		$this->limiter($security, false, null, $header)->beforeController($this->controller(), 'm');
	}

	public function testRateLimitAllowsWhenTheCheckErrors(): void {
		$security = $this->createMock(SecurityService::class);
		$security->method('checkAuthRateLimit')->willThrowException(new RuntimeException('apcu'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('failed open'));

		$this->limiter($security, logger: $logger)->beforeController($this->controller(), 'm');
	}

	public function testRateLimitTreatsAFailedReflectionAsProtected(): void {
		$security = $this->createMock(SecurityService::class);
		$security->method('getTrustedClientIpAddress')->willReturn('10.0.0.1');
		$security->expects($this->once())->method('checkAuthRateLimit')->willReturn(['allowed' => true]);
		$mw = new RateLimitMiddleware(new InboundCall($this->createMock(IRequest::class), $this->createMock(IUserSession::class), $this->reflector(true, true), $this->createMock(LoggerInterface::class)), $security);
		$mw->beforeController($this->controller(), 'm');
	}

	public function testRateLimitClearsCountersOnSuccess(): void {
		$security = $this->createMock(SecurityService::class);
		$security->expects($this->once())->method('recordSuccessfulAuth')->with('alice', '10.0.0.1');
		$response = new Response();
		$this->assertSame($response, $this->limiter($security, false, $this->user())->afterController($this->controller(), 'm', $response));

		$anonymous = $this->createMock(SecurityService::class);
		$anonymous->expects($this->never())->method('recordSuccessfulAuth');
		$this->assertSame($response, $this->limiter($anonymous)->afterController($this->controller(), 'm', $response));

		$failing = $this->createMock(SecurityService::class);
		$failing->method('recordSuccessfulAuth')->willThrowException(new RuntimeException('x'));
		$this->assertSame($response, $this->limiter($failing, false, $this->user())->afterController($this->controller(), 'm', $response));
	}

	public function testRateLimitRendersALockout(): void {
		$security = $this->createMock(SecurityService::class);
		$response = $this->limiter($security)->afterException($this->controller(), 'm', new AuthRateLimitExceededException('Locked.', 0));
		$this->assertSame(429, $response->getStatus());
		$this->assertSame(['error' => 'Locked.', 'lockout_until' => 0], $response->getData());
		$this->assertSame('1', $response->getHeaders()['Retry-After']);
	}

	/**
	 * Exceptions, whether they count as a failed auth, on a protected endpoint.
	 *
	 * @return array<string, array{0: string, 1: int, 2: bool}>
	 */
	public static function failures(): array {
		return [
			'not logged in' => ['NotLoggedInException', 0, true],
			'not admin' => ['NotAdminException', 0, true],
			'security 401' => ['SecurityException', 401, true],
			'security 403' => ['CorsSecurityException', 403, true],
			'security 500' => ['SecurityException', 500, false],
			'other 401' => ['SomethingException', 401, false],
		];
	}

	/**
	 * @dataProvider failures
	 */
	public function testRateLimitCountsOnlyAuthFailures(string $class, int $code, bool $counts): void {
		$security = $this->createMock(SecurityService::class);
		if ($counts === true) {
			$security->expects($this->once())->method('recordFailedAuthAttempt')->with(new FailedAttempt('', '10.0.0.1', 'inbound_auth_failed'));
		} else {
			$security->expects($this->never())->method('recordFailedAuthAttempt');
		}

		$exception = $this->exceptionNamed($class, $code);
		try {
			$this->limiter($security)->afterException($this->controller(), 'm', $exception);
			$this->fail('expected a rethrow');
		} catch (Exception $e) {
			$this->assertSame($exception, $e);
		}
	}

	public function testRateLimitDoesNotCountOnAPublicPage(): void {
		$security = $this->createMock(SecurityService::class);
		$security->expects($this->never())->method('recordFailedAuthAttempt');
		$exception = $this->exceptionNamed('NotLoggedInException', 0);
		$this->expectExceptionObject($exception);
		$this->limiter($security, true)->afterException($this->controller(), 'm', $exception);
	}

	/**
	 * An exception whose short class name is exactly $name.
	 *
	 * @param string $name Short class name.
	 * @param int    $code Exception code.
	 *
	 * @return Exception
	 */
	private function exceptionNamed(string $name, int $code): Exception {
		if (class_exists($name) === true) {
			return new $name('x', $code);
		}

		return $this->getMockBuilder(Exception::class)
			->setMockClassName($name)
			->setConstructorArgs(['x', $code])
			->getMock();
	}

	/**
	 * A caller middleware.
	 *
	 * @param string                        $path     Request path.
	 * @param CallerPolicy&MockObject       $policy   Policy double.
	 * @param CallerRateLimiter&MockObject  $limiter  Limiter double.
	 * @param ApiCallRecorder&MockObject    $recorder Recorder double.
	 * @param string|null                   $version  Served version id.
	 *
	 * @return ApiCallerMiddleware
	 */
	private function caller(string $path, CallerPolicy $policy, CallerRateLimiter $limiter, ApiCallRecorder $recorder, ?string $version='1'): ApiCallerMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		$request->method('getRemoteAddress')->willReturn('10.0.0.9');
		$request->method('getMethod')->willReturn('GET');
		$recorder->method('principal')->willReturn('caller-1');
		$negotiator = $this->createMock(ApiVersionNegotiator::class);
		$served = null;
		if ($version !== null) {
			$served = new ApiVersion(id: $version, lifecycle: new ApiVersionLifecycle(status: ApiVersion::STATUS_SUPPORTED));
		}

		$negotiator->method('negotiate')->willReturn(new ApiVersionNegotiation(null, $served, ApiVersionNegotiation::SOURCE_DEFAULT));

		return new ApiCallerMiddleware($request, new ApiCallerServices($recorder, $negotiator), $limiter, $policy);
	}

	public function testCallerIgnoresANonApiRequest(): void {
		$policy = $this->createMock(CallerPolicy::class);
		$policy->expects($this->never())->method('allowsAddress');
		$recorder = $this->createMock(ApiCallRecorder::class);
		$recorder->expects($this->never())->method('recordCall');
		$mw = $this->caller('/apps/openregister/', $policy, $this->createMock(CallerRateLimiter::class), $recorder);
		$mw->beforeController(null, 'm');
		$response = new Response();
		$this->assertSame($response, $mw->afterController(null, 'm', $response));
	}

	public function testCallerRefusesAWrongAddressBeforeSpendingTheBudget(): void {
		$policy = $this->createMock(CallerPolicy::class);
		$policy->method('allowsAddress')->with('caller-1', '10.0.0.9')->willReturn(false);
		$limiter = $this->createMock(CallerRateLimiter::class);
		$limiter->expects($this->never())->method('consume');
		try {
			$this->caller('/api/objects', $policy, $limiter, $this->createMock(ApiCallRecorder::class))->beforeController(null, 'm');
			$this->fail('expected a refusal');
		} catch (CallerRefusedException $e) {
			$this->assertSame(403, $e->toResponse()->getStatus());
		}
	}

	/**
	 * Ceilings and the window the refusal names.
	 *
	 * @return array<string, array{0: array<string, int>|null, 1: int}>
	 */
	public static function ceilings(): array {
		return [
			'administered window' => [['windowSeconds' => 30], 30],
			'default window' => [null, 60],
		];
	}

	/**
	 * @dataProvider ceilings
	 */
	public function testCallerRefusesOverItsCeiling(?array $ceiling, int $window): void {
		$policy = $this->createMock(CallerPolicy::class);
		$policy->method('allowsAddress')->willReturn(true);
		$policy->method('limitFor')->willReturn($ceiling);
		$limiter = $this->createMock(CallerRateLimiter::class);
		$limiter->method('consume')->willReturn(['allowed' => false, 'limit' => 5, 'resetAt' => 0]);
		try {
			$this->caller('/api/objects', $policy, $limiter, $this->createMock(ApiCallRecorder::class))->beforeController(null, 'm');
			$this->fail('expected a refusal');
		} catch (CallerRefusedException $e) {
			$response = $this->caller('/x', $policy, $limiter, $this->createMock(ApiCallRecorder::class))->afterException(null, 'm', $e);
			$this->assertSame(429, $response->getStatus());
			$this->assertSame(5, $response->getData()['limit']);
			$this->assertSame($window, $response->getData()['windowSeconds']);
		}
	}

	/**
	 * Allowed outcomes of the limiter.
	 *
	 * @return array<string, array{0: array<string, mixed>|null}>
	 */
	public static function allowedOutcomes(): array {
		return [
			'no ceiling' => [null],
			'under' => [['allowed' => true, 'limit' => 5, 'resetAt' => 0]],
		];
	}

	/**
	 * @dataProvider allowedOutcomes
	 */
	public function testCallerAllowsAndRecordsTheServedVersion(?array $outcome): void {
		$policy = $this->createMock(CallerPolicy::class);
		$policy->method('allowsAddress')->willReturn(true);
		$limiter = $this->createMock(CallerRateLimiter::class);
		$limiter->method('consume')->willReturn($outcome);
		$recorder = $this->createMock(ApiCallRecorder::class);
		$recorder->expects($this->once())->method('recordCall')->with(new ApiCallObservation('/api/objects', 'GET', '1'));
		$mw = $this->caller('/api/objects', $policy, $limiter, $recorder);
		$mw->beforeController(null, 'm');
		$response = new Response();
		$this->assertSame($response, $mw->afterController(null, 'm', $response));
	}

	public function testCallerRecordsNothingWithoutAServedVersion(): void {
		$policy = $this->createMock(CallerPolicy::class);
		$policy->method('allowsAddress')->willReturn(true);
		$recorder = $this->createMock(ApiCallRecorder::class);
		$recorder->expects($this->never())->method('recordCall');
		$mw = $this->caller('/api/objects', $policy, $this->createMock(CallerRateLimiter::class), $recorder, null);
		$mw->beforeController(null, 'm');
		$mw->afterController(null, 'm', new Response());
	}

	public function testCallerRethrowsAnythingElse(): void {
		$exception = new Exception('other');
		$this->expectExceptionObject($exception);
		$this->caller('/x', $this->createMock(CallerPolicy::class), $this->createMock(CallerRateLimiter::class), $this->createMock(ApiCallRecorder::class))
			->afterException(null, 'm', $exception);
	}

	/**
	 * A CORS middleware.
	 *
	 * @param string                                $origin    Origin header.
	 * @param IControllerMethodReflector&MockObject $reflector Reflector double.
	 * @param array<int, string>                    $allowlist Allowed origins.
	 *
	 * @return PublicApiCorsMiddleware
	 */
	private function cors(string $origin, IControllerMethodReflector $reflector, array $allowlist=[]): PublicApiCorsMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnMap([['Origin', $origin]]);
		$policy = $this->createMock(HardeningPolicy::class);
		$policy->method('allowedOrigins')->willReturn($allowlist);
		return new PublicApiCorsMiddleware($this->inboundCall($request, $this->createMock(IUserSession::class), null, $reflector), $policy);
	}

	/**
	 * CORS cases: origin, public, allowlist, reflected.
	 *
	 * @return array<string, array{0: string, 1: bool, 2: array<int, string>, 3: bool}>
	 */
	public static function corsCases(): array {
		return [
			'public, open list' => ['https://a.example', true, [], true],
			'public, listed' => ['https://a.example', true, ['https://a.example'], true],
			'public, off list' => ['https://b.example', true, ['https://a.example'], false],
			'not public' => ['https://a.example', false, [], false],
			'no origin' => ['', true, [], false],
		];
	}

	/**
	 * @dataProvider corsCases
	 */
	public function testCorsReflectsOnlyWhereAllowed(string $origin, bool $public, array $allowlist, bool $reflected): void {
		$reflector = $this->createMock(IControllerMethodReflector::class);
		$reflector->method('hasAnnotation')->willReturnMap([['PublicPage', $public]]);
		$response = $this->cors($origin, $reflector, $allowlist)->afterController($this->controller(), 'm', new Response());
		$headers = $response->getHeaders();
		$this->assertSame($reflected, isset($headers['Access-Control-Allow-Origin']));
		if ($reflected === true) {
			$this->assertSame($origin, $headers['Access-Control-Allow-Origin']);
			$this->assertSame('GET, POST, OPTIONS', $headers['Access-Control-Allow-Methods']);
			$this->assertSame('Content-Type', $headers['Access-Control-Allow-Headers']);
		}
	}

	public function testCorsFailsOpenWithoutAHeader(): void {
		$reflector = $this->createMock(IControllerMethodReflector::class);
		$reflector->method('hasAnnotation')->willThrowException(new RuntimeException('x'));
		$response = new Response();
		$this->assertSame($response, $this->cors('https://a.example', $reflector)->afterController($this->controller(), 'm', $response));
		$this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->getHeaders());
	}
}
