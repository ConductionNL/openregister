<?php

/**
 * Bounds a caller, binds it to its addresses, and records what it called.
 *
 * THE ORDER MATTERS AND IS NOT ARBITRARY.
 *
 *  1. The address binding, first. A call from an address the caller is not
 *     bound to should not consume that caller's rate budget: otherwise anyone
 *     who learns a uid can exhaust a legitimate integration's ceiling from
 *     anywhere in the world, turning an access control into a denial of
 *     service against the person it protects.
 *  2. The ceiling, second, on the calls that survive the binding.
 *  3. The record, last and only for calls that are actually served, because a
 *     record of refusals is a record of an attack, not of an integration, and
 *     the two answer different questions. An administrator reading "who still
 *     calls the deprecated endpoint" must not find a row for a caller whose
 *     every call was refused.
 *
 * 🔴 NOTHING HERE FAILS A CALL EXCEPT A DELIBERATE REFUSAL. The recorder
 * swallows, the limiter fails open, and the address binding only refuses when
 * an administrator has actually bound that caller. An instance that has
 * configured none of this behaves exactly as it did before.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use Exception;
use OCA\OpenRegister\Middleware\Exception\CallerRefusedException;
use OCA\OpenRegister\Service\ApiCaller\ApiCallRecorderInterface;
use OCA\OpenRegister\Service\ApiCaller\CallerPolicyInterface;
use OCA\OpenRegister\Service\ApiCaller\CallerRateLimiterInterface;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiatorInterface;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Applies the per-caller bounds and writes the caller record.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * Reason: `CallerRefusedException::overLimit()` and `::wrongAddress()` are
 *         named constructors, the same factory pattern phpmd's StaticAccess
 *         rule cannot tell apart from a hidden dependency.
 */
class ApiCallerMiddleware extends Middleware {

	/**
	 * The path fragment that marks a request as part of the API surface.
	 *
	 * @var string
	 */
	private const API_PATH_MARKER = '/api/';

	/**
	 * The version that answered this request, resolved once in beforeController.
	 *
	 * Held rather than re-negotiated in afterController because negotiating
	 * twice would read the catalogue twice for one call, and because a record
	 * naming a different version than the response header did would be a record
	 * nobody could reconcile.
	 *
	 * @var string|null
	 */
	private ?string $servedVersion = null;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The incoming request.
	 * @param ApiCallRecorderInterface $recorder Writes the caller record.
	 * @param CallerRateLimiterInterface $limiter Applies the administered ceiling.
	 * @param CallerPolicyInterface $policy Resolves the address binding.
	 * @param ApiVersionNegotiatorInterface $negotiator Names the contract that will answer.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly ApiCallRecorderInterface $recorder,
		private readonly CallerRateLimiterInterface $limiter,
		private readonly CallerPolicyInterface $policy,
		private readonly ApiVersionNegotiatorInterface $negotiator,
	) {

	}//end __construct()

	/**
	 * Refuse a caller off its address list or over its ceiling.
	 *
	 * @param mixed $controller The controller being dispatched.
	 * @param string $methodName The method being dispatched.
	 *
	 * @return void
	 *
	 * @throws CallerRefusedException When the caller is bounded out.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Fixed by the Middleware contract.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function beforeController($controller, $methodName): void {
		$this->servedVersion = null;
		if ($this->isApiRequest() === false) {
			return;
		}

		$this->refuseOutOfBounds(principal: $this->recorder->principal());
		$this->servedVersion = $this->negotiator->negotiate(request: $this->request)->version?->id;

	}//end beforeController()

	/**
	 * Record the call that was served.
	 *
	 * @param mixed $controller The controller that ran.
	 * @param string $methodName The method that ran.
	 * @param Response $response The response.
	 *
	 * @return Response The response, unchanged.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Fixed by the Middleware contract.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function afterController($controller, $methodName, Response $response): Response {
		if ($this->servedVersion === null) {
			return $response;
		}

		$path = $this->request->getPathInfo();
		if (is_string($path) === true && $path !== '') {
			$this->recorder->record(
				principal: $this->recorder->principal(),
				path: $path,
				method: $this->request->getMethod(),
				apiVersion: $this->servedVersion,
			);
		}

		return $response;

	}//end afterController()

	/**
	 * Turn a refusal into its response; re-throw anything else.
	 *
	 * @param mixed $controller The controller being dispatched.
	 * @param string $methodName The method being dispatched.
	 * @param Exception $exception The thrown exception.
	 *
	 * @return Response The refusal.
	 *
	 * @throws Exception The passed-in exception when it is not ours to handle.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Fixed by the Middleware contract.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md
	 */
	public function afterException($controller, $methodName, Exception $exception): Response {
		if ($exception instanceof CallerRefusedException) {
			return $exception->toResponse();
		}

		throw $exception;

	}//end afterException()

	/**
	 * Refuse a caller calling from off its address list, or over its ceiling.
	 *
	 * @param string $principal The caller.
	 *
	 * @return void
	 *
	 * @throws CallerRefusedException When the caller is bounded out.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	private function refuseOutOfBounds(string $principal): void {
		// The binding first: a call from the wrong address must not spend the
		// budget of the caller it is impersonating.
		if ($this->policy->allowsAddress(principal: $principal, address: $this->request->getRemoteAddress()) === false) {
			throw CallerRefusedException::wrongAddress();
		}

		$now = time();
		$outcome = $this->limiter->consume(principal: $principal, now: $now);
		if ($outcome !== null && $outcome['allowed'] === false) {
			throw $this->overLimit(principal: $principal, outcome: $outcome, now: $now);
		}

	}//end refuseOutOfBounds()

	/**
	 * The refusal for a caller over its administered ceiling.
	 *
	 * @param string $principal The caller.
	 * @param array<string, mixed> $outcome The limiter's verdict.
	 * @param int $now The current unix time.
	 *
	 * @return CallerRefusedException The refusal.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	private function overLimit(string $principal, array $outcome, int $now): CallerRefusedException {
		$ceiling = $this->policy->limitFor(principal: $principal);

		return CallerRefusedException::overLimit(
			limit: $outcome['limit'],
			windowSeconds: (int)($ceiling['windowSeconds'] ?? 60),
			resetAt: $outcome['resetAt'],
			now: $now,
		);

	}//end overLimit()

	/**
	 * Whether the current request is part of the API surface.
	 *
	 * @return bool True when the request path names an API route.
	 */
	private function isApiRequest(): bool {
		$path = $this->request->getPathInfo();
		if (is_string($path) === false || $path === '') {
			return false;
		}

		return str_contains($path, self::API_PATH_MARKER);

	}//end isApiRequest()
}//end class
