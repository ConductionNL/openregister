<?php

/**
 * Binds the grant of the personal API token that opened the request.
 *
 * Registered globally: a personal token can call any app's controller, and
 * every one of them that reaches OpenRegister's objects must meet the token's
 * ceiling, not only OpenRegister's own controllers. The binding itself is
 * {@see PersonalTokenGrantBinder}; this class only runs it before the
 * controller and answers the binder's rate-limit refusal with 429.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use Exception;
use OCA\OpenRegister\Exception\TokenRateLimitExceededException;
use OCA\OpenRegister\Kernel\Contracts\PersonalTokenGrantBinderInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;

/**
 * Binds a personal token's grant before the controller runs.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */
class PersonalTokenGrantMiddleware extends Middleware {

	/**
	 * Constructor.
	 *
	 * @param PersonalTokenGrantBinderInterface $binder Binds the calling token's grant.
	 */
	public function __construct(
		private readonly PersonalTokenGrantBinderInterface $binder,
	) {
	}//end __construct()

	/**
	 * Bind the calling token's grant.
	 *
	 * @param mixed  $controller The controller being dispatched.
	 * @param string $methodName The method being dispatched.
	 *
	 * @return void
	 *
	 * @throws TokenRateLimitExceededException When the call is over the grant's limit.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Fixed by the Middleware contract.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function beforeController($controller, $methodName): void {
		$this->binder->bind(now: time());
	}//end beforeController()

	/**
	 * Answer a call over the token's limit with 429 naming the limit; rethrow everything else.
	 *
	 * @param mixed     $controller The controller that was dispatched.
	 * @param string    $methodName The method that was dispatched.
	 * @param Exception $exception  The exception thrown.
	 *
	 * @return Response The 429 response.
	 *
	 * @throws Exception Every exception that is not this middleware's refusal.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Fixed by the Middleware contract.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#scenario-one-runaway-integration-does-not-take-the-balie-down
	 */
	public function afterException($controller, $methodName, Exception $exception): Response {
		if (($exception instanceof TokenRateLimitExceededException) === false) {
			throw $exception;
		}

		$details  = $exception->getDetails();
		$response = new JSONResponse(
			data: ['error' => $exception->getMessage(), 'details' => $details],
			statusCode: $exception->getStatusCode()
		);
		$response->addHeader('Retry-After', (string) ($details['retryAfter'] ?? 60));

		return $response;
	}//end afterException()
}//end class
