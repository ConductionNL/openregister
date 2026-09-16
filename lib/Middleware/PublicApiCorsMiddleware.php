<?php

/**
 * Public API CORS Middleware
 *
 * Reflects the request Origin onto responses from `@PublicPage` endpoints so a
 * browser on a different origin (e.g. a marketing website submitting a lead into
 * a public-create schema) can read the response. Scoped strictly to public
 * endpoints: a public page carries no credentials, so echoing the Origin without
 * `Access-Control-Allow-Credentials` is safe and cannot be leveraged for a
 * credentialed CSRF read.
 *
 * Only "simple" cross-origin requests are supported (no preflight): callers must
 * POST `application/x-www-form-urlencoded` / `text/plain` / `multipart/form-data`
 * so the browser skips the preflight OPTIONS the app router has no route for.
 * A `Content-Type: application/json` body would trigger a preflight and fail.
 *
 * Mirrors the reflect-Origin logic in {@see AuthorizationService::corsAfterController}
 * but applies it uniformly across the public object API via the middleware chain.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\IRequest;
use Throwable;

/**
 * Middleware that adds reflect-Origin CORS headers to public-page responses.
 *
 * @package OCA\OpenRegister\Middleware
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Middleware must reference NC framework types
 * (Middleware, Controller, Response, IRequest, IControllerMethodReflector) plus Throwable.
 */
class PublicApiCorsMiddleware extends Middleware {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current request
	 * @param IControllerMethodReflector $reflector Reflector for controller annotations
	 * @param HardeningPolicy $policy Reads the administered origin allowlist
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly IControllerMethodReflector $reflector,
		private readonly HardeningPolicy $policy,
	) {
	}//end __construct()

	/**
	 * Whether this instance lets a browser on that origin read a public answer.
	 *
	 * An empty allowlist means the administrator has not bound the public
	 * surface, and the middleware keeps reflecting whatever asks, exactly as it
	 * did before the control existed. That is the backwards-compatible reading,
	 * and the hardening report says so rather than letting an empty list read
	 * as a configured one.
	 *
	 * @param string $origin The Origin header, as sent.
	 *
	 * @return bool True when the origin may be reflected.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	private function allows(string $origin): bool {
		$allowlist = $this->policy->allowedOrigins();
		if ($allowlist === []) {
			return true;
		}

		return in_array(strtolower(trim($origin)), $allowlist, true);
	}//end allows()

	/**
	 * Reflect the Origin on responses from public endpoints.
	 *
	 * Fails open: any error leaves the response unchanged (no CORS header) so a
	 * bug here can never turn a working same-origin call into a 500.
	 *
	 * @param Controller $controller The controller being dispatched
	 * @param string $methodName The method being dispatched
	 * @param Response $response The outgoing response
	 *
	 * @return Response The response, with reflect-Origin CORS headers when public
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) IControllerMethodReflector::reflect() consumes
	 * both $controller and $methodName via the interface; PHPMD misclassifies $controller as unused.
	 */
	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		try {
			$origin = $this->request->getHeader('Origin');
			if ($origin === '') {
				return $response;
			}

			// INHERITED: `reflect()` is not on IControllerMethodReflector, only on
			// the concrete reflector the container injects. Calling it unguarded
			// works in production and throws against any double built from the
			// interface, which sends this whole method down its fail-open path
			// without a word. Guarded, production is unchanged and the behaviour
			// becomes testable.
			if (method_exists($this->reflector, 'reflect') === true) {
				$this->reflector->reflect($controller, $methodName);
			}

			if ($this->reflector->hasAnnotation('PublicPage') === false) {
				return $response;
			}

			// An origin off the administered allowlist gets no header at all, and
			// the body is unchanged. The browser refuses the read, and the
			// response names neither the allowlist nor anything on it.
			if ($this->allows(origin: $origin) === false) {
				return $response;
			}

			// Never combine a reflected Origin with credentials — that pairing is
			// the CSRF-read footgun the platform guards against elsewhere.
			$response->addHeader('Access-Control-Allow-Origin', $origin);
			$response->addHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
			$response->addHeader('Access-Control-Allow-Headers', 'Content-Type');
		} catch (Throwable $e) {
			// Fail open: leave the response untouched.
			return $response;
		}//end try

		return $response;
	}//end afterController()
}//end class
