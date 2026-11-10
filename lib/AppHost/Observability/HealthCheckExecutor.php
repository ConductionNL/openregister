<?php

/**
 * OpenRegister AppHost — Health Check Executor
 *
 * Executes the five closed health-check primitives (database, filesystem,
 * appEnabled, appConfig, orAvailable) declared in an app's manifest, merges
 * any IHealthCheckProvider escape-hatch results, and resolves the overall
 * status + HTTP code under the active statusCodePolicy (ADR-006 / ADR-040).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability;

use OCA\OpenRegister\AppHost\IHealthCheckProvider;
use OCP\AppFramework\Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs declarative health checks and resolves the response status + code.
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-2.1
 */
class HealthCheckExecutor {
	private const STATUS_OK = 'ok';

	private const STATUS_DEGRADED = 'degraded';

	private const STATUS_ERROR = 'error';

	/**
	 * Constructor.
	 *
	 * @param LocalHealthChecks   $localChecks Database, filesystem, appEnabled and appConfig checks.
	 * @param AppProviderResolver $providers   Resolves the app's IHealthCheckProvider (and orAvailable).
	 * @param LoggerInterface     $logger      PSR logger (exception messages logged, never returned).
	 */
	public function __construct(
		private readonly LocalHealthChecks $localChecks,
		private readonly AppProviderResolver $providers,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Execute all health checks for the given manifest and resolve the result.
	 *
	 * @param ObservabilityManifest $manifest The app's observability config.
	 *
	 * @return HealthResult
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-2.2
	 */
	public function execute(ObservabilityManifest $manifest): HealthResult {
		$checks = [];
		$worstStatus = self::STATUS_OK;

		foreach ($manifest->checks as $descriptor) {
			[$ok, $message] = $this->runCheck(appId: $manifest->appId, descriptor: $descriptor);
			$checks[$descriptor->id] = $this->formatCheckResult(ok: $ok, message: $message);

			if ($ok === false) {
				$worstStatus = $this->escalate(current: $worstStatus, severity: $descriptor->severity);
			}
		}

		// Merge IHealthCheckProvider escape-hatch results.
		foreach ($this->resolveProviderResults(appId: $manifest->appId) as $id => $result) {
			$ok = ($result['ok'] ?? false) === true;
			$severity = ($result['severity'] ?? 'critical');
			if (in_array($severity, HealthCheckDescriptor::SEVERITIES, true) === false) {
				$severity = 'critical';
			}

			$message = (string)($result['message'] ?? '');
			$checks[$id] = $this->formatCheckResult(ok: $ok, message: $message);
			if ($ok === false) {
				$worstStatus = $this->escalate(current: $worstStatus, severity: $severity);
			}
		}

		return new HealthResult(
			status: $worstStatus,
			checks: $checks,
			httpStatusCode: $this->resolveHttpCode(status: $worstStatus, policy: $manifest->statusCodePolicy)
		);
	}//end execute()

	/**
	 * Run a single check descriptor.
	 *
	 * @param string $appId Calling app id.
	 * @param HealthCheckDescriptor $descriptor The check.
	 *
	 * @return array{0: bool, 1: string} [ok, generic failure message]
	 */
	private function runCheck(string $appId, HealthCheckDescriptor $descriptor): array {
		try {
			if ($descriptor->type === 'orAvailable') {
				return $this->checkOrAvailable();
			}

			return ($this->localChecks->run(appId: $appId, descriptor: $descriptor) ?? [false, 'unknown check']);
		} catch (Throwable $e) {
			// Log internals; return a generic message so health never leaks.
			$logMessage = sprintf(
				'[AppHost\\Health] Check "%s" (%s) for app "%s" threw: %s',
				$descriptor->id,
				$descriptor->type,
				$appId,
				$e->getMessage()
			);
			$this->logger->warning(
				message: $logMessage,
				context: ['file' => __FILE__, 'line' => __LINE__, 'app' => $appId]
			);
			return [false, 'check error'];
		}//end try
	}//end runCheck()

	/**
	 * Format a check outcome for the `checks` response map.
	 *
	 * @param bool $ok Whether the check passed.
	 * @param string $message Generic failure message (only used on failure).
	 *
	 * @return string "ok" or "failed[: message]".
	 */
	private function formatCheckResult(bool $ok, string $message): string {
		if ($ok === true) {
			return self::STATUS_OK;
		}

		if ($message === '') {
			return 'failed';
		}

		return 'failed: ' . $message;
	}//end formatCheckResult()

	/**
	 * OpenRegister availability — resolve ObjectService, non-null.
	 *
	 * @return array{0: bool, 1: string}
	 */
	private function checkOrAvailable(): array {
		$service = $this->providers->own()->get(\OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::class);
		if ($service !== null) {
			return [true, ''];
		}

		return [false, 'openregister unavailable'];
	}//end checkOrAvailable()

	/**
	 * Resolve and run the app's IHealthCheckProvider, if any.
	 *
	 * @param string $appId Calling app id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function resolveProviderResults(string $appId): array {
		try {
			$provider = $this->providers->resolve(appId: $appId, interface: IHealthCheckProvider::class);
			if ($provider === null) {
				return [];
			}

			return $provider->check();
		} catch (Throwable $e) {
			$this->logger->warning(
				message: sprintf('[AppHost\\Health] Provider for app "%s" threw: %s', $appId, $e->getMessage()),
				context: ['file' => __FILE__, 'line' => __LINE__, 'app' => $appId]
			);
			return [];
		}//end try
	}//end resolveProviderResults()

	/**
	 * Escalate the running status given a failed check's severity.
	 *
	 * @param string $current Current worst status.
	 * @param string $severity critical|degraded.
	 *
	 * @return string New worst status.
	 */
	private function escalate(string $current, string $severity): string {
		if ($severity === 'critical') {
			return self::STATUS_ERROR;
		}

		// Degraded failure cannot downgrade an already-error status.
		if ($current === self::STATUS_ERROR) {
			return self::STATUS_ERROR;
		}

		return self::STATUS_DEGRADED;
	}//end escalate()

	/**
	 * Resolve the HTTP status code under the active policy.
	 *
	 * @param string $status ok|degraded|error.
	 * @param string $policy adr006|always200.
	 *
	 * @return int HTTP code.
	 */
	private function resolveHttpCode(string $status, string $policy): int {
		if ($policy === ObservabilityManifest::POLICY_ALWAYS200) {
			return Http::STATUS_OK;
		}

		// The adr006 policy returns 503 only when a critical check failed
		// (status === error); everything else stays 200.
		if ($status === self::STATUS_ERROR) {
			return Http::STATUS_SERVICE_UNAVAILABLE;
		}

		return Http::STATUS_OK;
	}//end resolveHttpCode()
}//end class
