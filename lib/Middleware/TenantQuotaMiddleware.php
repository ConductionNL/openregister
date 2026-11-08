<?php

/**
 * Tenant Quota Middleware
 *
 * Enforces per-organisation request quotas, bandwidth quotas, and organisation
 * status checks before controller execution. Uses APCu for high-performance
 * counter management.
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
 * @spec openspec/specs/tenant-quotas/spec.md#requirement-request-quota-must-be-enforced-via-middleware-before-controller-execution
 * @spec openspec/specs/tenant-quotas/spec.md
 * @spec openspec/specs/tenant-quotas/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use DateTime;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\TenantLifecycleServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Middleware that enforces tenant quotas and organisation status.
 *
 * @package OCA\OpenRegister\Middleware
 *
 * @spec openspec/specs/tenant-quotas/spec.md#requirement-request-quota-must-be-enforced-via-middleware-before-controller-execution
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TenantQuotaMiddleware extends Middleware {
	/**
	 * Environment-based request quota multipliers.
	 */
	private const ENV_REQUEST_MULTIPLIER = [
		TenantLifecycleServiceInterface::ENV_DEVELOPMENT => 10,
		TenantLifecycleServiceInterface::ENV_TEST => 5,
		TenantLifecycleServiceInterface::ENV_ACCEPTANCE => 2,
		TenantLifecycleServiceInterface::ENV_PRODUCTION => 1,
	];

	/**
	 * Environment-based bandwidth quota multipliers.
	 */
	private const ENV_BANDWIDTH_MULTIPLIER = [
		TenantLifecycleServiceInterface::ENV_DEVELOPMENT => 5,
		TenantLifecycleServiceInterface::ENV_TEST => 3,
		TenantLifecycleServiceInterface::ENV_ACCEPTANCE => 2,
		TenantLifecycleServiceInterface::ENV_PRODUCTION => 1,
	];

	/**
	 * Constructor
	 *
	 * @param OrganisationServiceInterface $organisationService Organisation service
	 * @param IUserSession $userSession User session
	 * @param IGroupManager $groupManager Group manager
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly OrganisationServiceInterface $organisationService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The refusal message for each organisation status nobody may work in.
	 */
	private const REFUSED_STATUSES = [
		TenantLifecycleServiceInterface::STATUS_SUSPENDED => 'Organisation is suspended',
		TenantLifecycleServiceInterface::STATUS_DEPROVISIONING => 'Organisation is being deprovisioned',
		// Retained: the tenancy ended and the data is kept, so nobody works in it.
		TenantLifecycleServiceInterface::STATUS_RETAINED => 'Organisation access has ended and its data is retained',
	];

	/**
	 * Check quotas and organisation status before controller execution.
	 *
	 * @param string $controller Controller class name
	 * @param string $methodName Method name
	 *
	 * @return void
	 *
	 * @throws \OCP\AppFramework\Http\TenantQuotaExceededException
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-request-quota-must-be-enforced-via-middleware-before-controller-execution
	 * @spec openspec/specs/tenant-quotas/spec.md
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-a-terminated-organisation-must-be-able-to-keep-its-data-in-the-retained-state
	 */
	public function beforeController(string|Controller $controller, string $methodName): void {
		// Skip for non-authenticated requests (public endpoints).
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		$organisation = $this->loadOrganisation(where: '');
		if ($organisation === null) {
			return;
		}

		$this->assertStatusAllows(organisation: $organisation, user: $user);
		$this->checkRequestQuota(organisation: $organisation);
	}//end beforeController()

	/**
	 * Track bandwidth after controller execution.
	 *
	 * @param string $controller Controller class name
	 * @param string $methodName Method name
	 * @param Response $response The response
	 *
	 * @return Response The unmodified response
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-request-quota-must-be-enforced-via-middleware-before-controller-execution
	 * @spec openspec/specs/tenant-quotas/spec.md
	 */
	public function afterController(string|Controller $controller, string $methodName, Response $response): Response {
		$orgUuid = $this->loadOrganisation(where: ' in afterController')?->getUuid();
		if ($orgUuid === null) {
			return $response;
		}

		$this->trackBandwidth(orgUuid: $orgUuid, contentLength: $this->contentLength(response: $response));

		return $response;
	}//end afterController()

	/**
	 * Handle exceptions thrown during quota/status checks.
	 *
	 * @param string $controller Controller class name
	 * @param string $methodName Method name
	 * @param \Exception $exception The exception
	 *
	 * @return Response|null A JSON error response or null to re-throw
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md
	 */
	public function afterException(string|Controller $controller, string $methodName, \Exception $exception): ?Response {
		if ($exception instanceof TenantStatusException) {
			return $this->statusResponse(exception: $exception);
		}

		if ($exception instanceof TenantQuotaExceededException) {
			return $this->quotaResponse(exception: $exception);
		}

		throw $exception;
	}//end afterException()

	/**
	 * Load the active organisation, or null when it cannot be loaded.
	 *
	 * Organisation loading may fail during migrations or schema changes;
	 * quota checking is then skipped rather than blocking all requests.
	 *
	 * @param string $where Where the load happens, for the log line.
	 *
	 * @return \OCA\OpenRegister\Db\Organisation|null The active organisation
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md
	 */
	private function loadOrganisation(string $where): ?object {
		try {
			return $this->organisationService->getActiveOrganisation();
		} catch (\Exception $e) {
			$this->logger->warning('[TenantQuotaMiddleware] Could not load organisation' . $where . ': ' . $e->getMessage());
			return null;
		}
	}//end loadOrganisation()

	/**
	 * Refuse an organisation whose status lets nobody (or only an admin) work in it.
	 *
	 * @param \OCA\OpenRegister\Db\Organisation $organisation The active organisation
	 * @param IUser $user The signed-in user
	 *
	 * @return void
	 *
	 * @throws TenantStatusException When the status refuses the request
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-a-terminated-organisation-must-be-able-to-keep-its-data-in-the-retained-state
	 */
	private function assertStatusAllows(object $organisation, IUser $user): void {
		$status = $organisation->getStatus() ?? TenantLifecycleServiceInterface::STATUS_ACTIVE;
		$message = self::REFUSED_STATUSES[$status] ?? null;
		if ($status === TenantLifecycleServiceInterface::STATUS_PROVISIONING
			&& $this->groupManager->isAdmin($user->getUID()) === false
		) {
			$message = 'Organisation is being provisioned';
		}

		if ($message !== null) {
			throw new TenantStatusException($message, $status, 403);
		}
	}//end assertStatusAllows()

	/**
	 * Check request quota using APCu counters.
	 *
	 * @param \OCA\OpenRegister\Db\Organisation $organisation The active organisation
	 *
	 * @return void
	 *
	 * @throws TenantQuotaExceededException If quota is exceeded
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-request-quota-must-be-enforced-via-middleware-before-controller-execution
	 */
	private function checkRequestQuota(object $organisation): void {
		$effectiveQuota = $this->effectiveRequestQuota(organisation: $organisation);
		// Null quota means unlimited; without APCu quota enforcement is skipped.
		if ($effectiveQuota === null || $this->apcuAvailable() === false) {
			return;
		}

		$counterKey = $this->hourKey(prefix: 'or_quota', orgUuid: (string)$organisation->getUuid());
		if ($this->counterValue(key: $counterKey) >= $effectiveQuota) {
			throw $this->quotaExceeded(effectiveQuota: $effectiveQuota);
		}

		$this->bumpCounter(key: $counterKey, amount: 1);
	}//end checkRequestQuota()

	/**
	 * The organisation's request quota with its environment multiplier applied.
	 *
	 * @param \OCA\OpenRegister\Db\Organisation $organisation The active organisation
	 *
	 * @return int|null The effective quota, or null when unlimited
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md
	 */
	private function effectiveRequestQuota(object $organisation): ?int {
		$requestQuota = $organisation->getRequestQuota();
		if ($requestQuota === null) {
			return null;
		}

		$environment = $organisation->getEnvironment() ?? TenantLifecycleServiceInterface::ENV_PRODUCTION;
		return $requestQuota * (self::ENV_REQUEST_MULTIPLIER[$environment] ?? 1);
	}//end effectiveRequestQuota()

	/**
	 * The refusal for an organisation over its request quota this hour.
	 *
	 * @param int $effectiveQuota The quota that was reached
	 *
	 * @return TenantQuotaExceededException The refusal, resetting at the next hour
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md
	 */
	private function quotaExceeded(int $effectiveQuota): TenantQuotaExceededException {
		// Calculate seconds until next hour.
		$now = new DateTime();
		$nextHour = new DateTime();
		$nextHour->modify('+1 hour');
		$nextHour->setTime((int)$nextHour->format('H'), 0, 0);
		$retryAfter = $nextHour->getTimestamp() - $now->getTimestamp();

		return new TenantQuotaExceededException(
			'Request quota exceeded',
			$effectiveQuota,
			$nextHour->format('c'),
			max(1, $retryAfter)
		);
	}//end quotaExceeded()

	/**
	 * Add a response's size to this hour's bandwidth counter.
	 *
	 * @param string $orgUuid The organisation's UUID
	 * @param int $contentLength The response size in bytes
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md
	 */
	private function trackBandwidth(string $orgUuid, int $contentLength): void {
		if ($contentLength <= 0 || $this->apcuAvailable() === false) {
			return;
		}

		$this->bumpCounter(key: $this->hourKey(prefix: 'or_bw', orgUuid: $orgUuid), amount: $contentLength);
	}//end trackBandwidth()

	/**
	 * The size of a response's body: the encoded JSON data, 0 for anything else.
	 *
	 * @param Response $response The response
	 *
	 * @return int The content length in bytes
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md
	 */
	private function contentLength(Response $response): int {
		if ($response instanceof JSONResponse === false) {
			return 0;
		}

		$encoded = json_encode($response->getData());
		if ($encoded === false) {
			return 0;
		}

		return strlen($encoded);
	}//end contentLength()

	/**
	 * The response for a refused organisation status.
	 *
	 * @param TenantStatusException $exception The refusal
	 *
	 * @return JSONResponse The error and the status, with the refusal's code
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md
	 */
	private function statusResponse(TenantStatusException $exception): JSONResponse {
		return new JSONResponse(
			[
				'error' => $exception->getMessage(),
				'status' => $exception->getStatus(),
			],
			$exception->getCode()
		);
	}//end statusResponse()

	/**
	 * The 429 response for an exceeded quota, with a Retry-After header.
	 *
	 * @param TenantQuotaExceededException $exception The refusal
	 *
	 * @return JSONResponse The error, the quota and when it resets
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md
	 */
	private function quotaResponse(TenantQuotaExceededException $exception): JSONResponse {
		$response = new JSONResponse(
			[
				'error' => $exception->getMessage(),
				'quota' => $exception->getQuota(),
				'resetAt' => $exception->getResetAt(),
			],
			429
		);

		$response->addHeader('Retry-After', (string)$exception->getRetryAfter());

		return $response;
	}//end quotaResponse()

	/**
	 * Whether APCu counters can be used.
	 *
	 * @return bool True when APCu is loaded and enabled
	 */
	private function apcuAvailable(): bool {
		return function_exists('apcu_enabled') === true && apcu_enabled() === true;
	}//end apcuAvailable()

	/**
	 * The counter key of an organisation for the current hour.
	 *
	 * @param string $prefix The counter's prefix
	 * @param string $orgUuid The organisation's UUID
	 *
	 * @return string The key
	 */
	private function hourKey(string $prefix, string $orgUuid): string {
		return $prefix . '_' . $orgUuid . '_' . (new DateTime())->format('YmdH');
	}//end hourKey()

	/**
	 * The current value of an APCu counter, 0 when it is not set.
	 *
	 * @param string $key The counter key
	 *
	 * @return mixed The stored count
	 */
	private function counterValue(string $key): mixed {
		$success = false;
		$count = apcu_fetch($key, $success);
		if ($success === false) {
			return 0;
		}

		return $count;
	}//end counterValue()

	/**
	 * Increment an APCu counter, creating it (TTL: 2 hours, to survive the hour boundary).
	 *
	 * @param string $key The counter key
	 * @param int $amount The increment
	 *
	 * @return void
	 */
	private function bumpCounter(string $key, int $amount): void {
		$success = false;
		apcu_inc($key, $amount, $success);
		if ($success === false) {
			apcu_store($key, $amount, 7200);
		}
	}//end bumpCounter()
}//end class
