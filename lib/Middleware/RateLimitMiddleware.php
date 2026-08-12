<?php

/**
 * Rate Limit Middleware
 *
 * Wires SecurityService brute-force protection into the inbound API
 * authentication path (issue #1834). Nextcloud's core SecurityMiddleware
 * runs BEFORE app middleware and throws NotLoggedInException / NotAdminException
 * when Basic/Bearer/session credentials fail on a protected endpoint. Because
 * app middleware is wrapped OUTSIDE the core middleware in the dispatch chain,
 * this middleware's afterException() observes those failures and records them
 * for rate-limiting; its beforeController() pre-emptively blocks once a
 * (identity + IP) pair is locked out.
 *
 * Design guarantees:
 * - FAIL-OPEN: any error inside this middleware allows the request through.
 *   A bug in the limiter must never deny service to every client.
 * - Only failures on PROTECTED endpoints are counted. Public pages
 *   (@PublicPage) are never rate-limited here.
 * - Successful authenticated requests are NEVER blocked; they only clear
 *   accumulated failure counters for that identity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use OCA\OpenRegister\Service\SecurityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;

/**
 * Middleware that rate-limits failed inbound-API authentication attempts.
 *
 * @package OCA\OpenRegister\Middleware
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Middleware must reference NC framework types
 * (Middleware, Controller, Response, JSONResponse, Http, IRequest, IUserSession,
 * IControllerMethodReflector) plus SecurityService, LoggerInterface, ReflectionClass, and
 * Throwable — every type is required by the framework contract or the rate-limiting logic.
 */
class RateLimitMiddleware extends Middleware {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current request
	 * @param IUserSession $userSession The user session
	 * @param IControllerMethodReflector $reflector Reflector for controller annotations
	 * @param SecurityService $securityService The security service
	 * @param LoggerInterface $logger Logger for security events
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IControllerMethodReflector $reflector,
		private readonly SecurityService $securityService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Pre-emptively block a request whose (identity + IP) pair is locked out.
	 *
	 * Runs before the controller. Skips public pages entirely. Fails OPEN:
	 * any error here allows the request through.
	 *
	 * @param Controller $controller The controller being dispatched
	 * @param string $methodName The method being dispatched
	 *
	 * @return void
	 *
	 * @throws AuthRateLimitExceededException When the identity+IP is locked out
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) NC Middleware::beforeController() requires the
	 * ($controller, $methodName) signature; $controller is forwarded to isPublicPage() but $methodName is
	 * also needed there — PHPMD misclassifies the forwarded params as unused.
	 * @SuppressWarnings(PHPMD.ShortVariable)         $ip is the established abbreviation for IP address in
	 * network-security code; renaming to $ipAddress would conflict with the SecurityService parameter name.
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
	 */
	public function beforeController(Controller $controller, string $methodName): void {
		try {
			// Only guard protected endpoints. Public pages are out of scope.
			if ($this->isPublicPage(controller: $controller, methodName: $methodName) === true) {
				return;
			}

			$ip = $this->securityService->getTrustedClientIpAddress(request: $this->request);
			$identity = $this->resolveIdentity();

			$check = $this->securityService->checkAuthRateLimit(identity: $identity, ipAddress: $ip);
			if (($check['allowed'] ?? true) === false) {
				throw new AuthRateLimitExceededException(
					message: (string)($check['reason'] ?? 'Too many failed authentication attempts.'),
					lockoutUntil: (int)($check['lockout_until'] ?? 0)
				);
			}
		} catch (AuthRateLimitExceededException $e) {
			// Intentional lockout — let it propagate to afterException().
			throw $e;
		} catch (Throwable $e) {
			// FAIL-OPEN: never block a request because the limiter errored.
			$this->logger->warning(
				'[RateLimitMiddleware] Rate-limit precheck failed open: ' . $e->getMessage(),
				['file' => __FILE__, 'line' => __LINE__]
			);
		}//end try
	}//end beforeController()

	/**
	 * Clear failure counters after a successful authenticated request.
	 *
	 * Fails OPEN: never alters the response on error.
	 *
	 * @param Controller $controller The controller that was dispatched
	 * @param string $methodName The method that was dispatched
	 * @param Response $response The response produced
	 *
	 * @return Response The unmodified response
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) NC Middleware::afterController() mandates
	 * ($controller, $methodName, $response) — $controller and $methodName are not used in this method's
	 * body (only $response and the session are needed), but the framework dispatch requires all three.
	 * @SuppressWarnings(PHPMD.ShortVariable)         $ip is the established abbreviation for IP address;
	 * renaming disagrees with SecurityService's own parameter naming.
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
	 */
	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return $response;
			}

			$ip = $this->securityService->getTrustedClientIpAddress(request: $this->request);
			$this->securityService->recordSuccessfulAuth(identity: $user->getUID(), ipAddress: $ip);
		} catch (Throwable $e) {
			$this->logger->debug(
				'[RateLimitMiddleware] recordSuccessfulAuth failed open: ' . $e->getMessage(),
				['file' => __FILE__, 'line' => __LINE__]
			);
		}

		return $response;
	}//end afterController()

	/**
	 * Record auth failures and surface the lockout response.
	 *
	 * Recognises:
	 * - AuthRateLimitExceededException (thrown by beforeController): returns 429.
	 * - Nextcloud core auth failures (NotLoggedInException / NotAdminException
	 *   / SecurityException with a 401/403 status) on a protected endpoint:
	 *   records a failed attempt, then RE-THROWS the original exception so
	 *   the normal Nextcloud error handling is preserved.
	 *
	 * Fails OPEN: recording errors never change the outcome; the original
	 * exception is always preserved for unrecognised cases.
	 *
	 * @param Controller $controller The controller that was dispatched
	 * @param string $methodName The method that was dispatched
	 * @param \Exception $exception The thrown exception
	 *
	 * @return Response|null A 429 response for an enforced lockout, otherwise null
	 *
	 * @throws \Exception The original exception is re-thrown for all non-lockout cases
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) NC Middleware::afterException() requires
	 * ($controller, $methodName, $exception) — $controller is forwarded to isPublicPage() but PHPMD
	 * flags it; $methodName is also required by isPublicPage().
	 * @SuppressWarnings(PHPMD.ShortVariable)         $ip is the established abbreviation for IP address;
	 * renaming disagrees with SecurityService's own parameter naming.
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
	 */
	public function afterException(Controller $controller, string $methodName, \Exception $exception): ?Response {
		// Enforced lockout: return a clean 429.
		if ($exception instanceof AuthRateLimitExceededException) {
			$response = new JSONResponse(
				['error' => $exception->getMessage(), 'lockout_until' => $exception->getLockoutUntil()],
				Http::STATUS_TOO_MANY_REQUESTS
			);

			$retryAfter = max(1, ($exception->getLockoutUntil() - time()));
			$response->addHeader('Retry-After', (string)$retryAfter);

			return $response;
		}

		// Otherwise: only count genuine auth failures on protected endpoints,
		// then re-throw so Nextcloud handles the response unchanged.
		try {
			if ($this->isAuthFailure(exception: $exception) === true
				&& $this->isPublicPage(controller: $controller, methodName: $methodName) === false
			) {
				$ip = $this->securityService->getTrustedClientIpAddress(request: $this->request);
				$identity = $this->resolveIdentity();
				$this->securityService->recordFailedAuthAttempt(
					identity: $identity,
					ipAddress: $ip,
					reason: 'inbound_auth_failed'
				);
			}
		} catch (Throwable $e) {
			// FAIL-OPEN: recording must not interfere with the original error.
			$this->logger->warning(
				'[RateLimitMiddleware] recordFailedAuthAttempt failed open: ' . $e->getMessage(),
				['file' => __FILE__, 'line' => __LINE__]
			);
		}

		throw $exception;
	}//end afterException()

	/**
	 * Determine whether the dispatched method is a public page.
	 *
	 * @param Controller $controller The controller being dispatched
	 * @param string $methodName The method being dispatched
	 *
	 * @return bool True when the endpoint is public (skip rate-limiting)
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) IControllerMethodReflector::reflect() requires both
	 * the controller instance and method name; PHPMD misclassifies $controller as unused because it is
	 * consumed via the interface method, not directly in the body.
	 */
	private function isPublicPage(Controller $controller, string $methodName): bool {
		try {
			$this->reflector->reflect($controller, $methodName);
			return $this->reflector->hasAnnotation('PublicPage');
		} catch (Throwable $e) {
			// If reflection fails, treat as protected (safer: we may rate-limit,
			// but we never accidentally exempt a protected endpoint).
			return false;
		}
	}//end isPublicPage()

	/**
	 * Decide whether an exception represents a failed authentication on a
	 * protected endpoint that should count toward brute-force limits.
	 *
	 * Matches Nextcloud's auth exceptions by class short-name (they live in
	 * the private OC\ namespace) and any SecurityException carrying a 401/403
	 * status code.
	 *
	 * @param \Throwable $exception The exception to classify
	 *
	 * @return bool True when the exception is an auth failure
	 */
	private function isAuthFailure(\Throwable $exception): bool {
		$shortName = (new ReflectionClass($exception))->getShortName();
		if (in_array($shortName, ['NotLoggedInException', 'NotAdminException'], true) === true) {
			return true;
		}

		// SecurityException (e.g. forbidden password login, CORS basic-auth)
		// carries an HTTP status; treat 401/403 as auth failures.
		$code = $exception->getCode();
		if (($code === Http::STATUS_UNAUTHORIZED || $code === Http::STATUS_FORBIDDEN)
			&& str_contains(strtolower($shortName), 'security') === true
		) {
			return true;
		}

		return false;
	}//end isAuthFailure()

	/**
	 * Resolve the identity for keying the rate-limit counter.
	 *
	 * Prefers the currently logged-in user (success path / partial auth);
	 * otherwise extracts the attempted username from an HTTP Basic
	 * Authorization header (NEVER the password). Falls back to '' (anonymous)
	 * when no identity is discoverable, in which case the per-IP backstop
	 * still applies.
	 *
	 * @return string The identity, or '' when unknown
	 */
	private function resolveIdentity(): string {
		try {
			$user = $this->userSession->getUser();
			if ($user !== null) {
				return $user->getUID();
			}

			$authHeader = $this->request->getHeader('Authorization');
			if ($authHeader !== '' && stripos($authHeader, 'Basic ') === 0) {
				$decoded = base64_decode(substr($authHeader, 6), true);
				if ($decoded !== false && str_contains($decoded, ':') === true) {
					[$username] = explode(':', $decoded, 2);
					return $username;
				}
			}
		} catch (Throwable $e) {
			// Fall through to anonymous.
			unset($e);
		}

		return '';
	}//end resolveIdentity()
}//end class
