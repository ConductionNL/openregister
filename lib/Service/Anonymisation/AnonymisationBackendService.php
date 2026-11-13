<?php

/**
 * OpenRegister Anonymisation Backend Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Anonymisation
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use OCA\OpenRegister\Exception\AnalyzeRequestRejectedException;
use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Single source of truth for anonymisation backend selection and availability.
 *
 * Owns the only implementation of the effectiveMethod precedence rule, the
 * AppAPI/IAppManager-based detection for OpenAnonymiser, the HTTP probes for
 * Presidio, and the probe-result cache. All consumers (in-process callers, the
 * OCS controller, the admin UI) MUST obtain state via getState().
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Aggregates several OCP services by design.
 */
class AnonymisationBackendService implements AnonymisationBackendServiceInterface, AnonymiserRequestInterface {
	/**
	 * App id of the AppAPI app required for ExApp detection.
	 */
	private const APP_API = 'app_api';

	/**
	 * App id of the full OpenAnonymiser ExApp (preferred variant).
	 */
	private const EXAPP_FULL = 'openanonymiser';

	/**
	 * App id of the light OpenAnonymiser ExApp.
	 */
	private const EXAPP_LIGHT = 'openanonymiser_light';

	/**
	 * The cached probe result of each backend.
	 *
	 * @var ProbeResultCache
	 */
	private readonly ProbeResultCache $probeCache;

	/**
	 * App manager used for ExApp detection.
	 *
	 * @var IAppManager
	 */
	private readonly IAppManager $appManager;

	/**
	 * App configuration store.
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $appConfig;

	/**
	 * HTTP client factory for endpoint probes.
	 *
	 * @var IClientService
	 */
	private readonly IClientService $clientService;

	/**
	 * Reads the stored entity-recognition settings.
	 *
	 * @var FileSettingsHandler
	 */
	private readonly FileSettingsHandler $fileSettingsHandler;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Reports an OpenAnonymiser connection test to integriq's connection registry.
	 *
	 * @var ConnectionReporter
	 */
	private readonly ConnectionReporter $connectionReporter;

	/**
	 * The next step an unconfigured OpenAnonymiser connection reports, by probe error.
	 *
	 * @var array<string, string>
	 */
	private const OPENANONYMISER_NEXT_STEPS = [
		ProbeResult::ERROR_APPAPI_MISSING => 'AppAPI is not installed. Install AppAPI, then the OpenAnonymiser ExApp.',
		ProbeResult::ERROR_EXAPP_NOT_INSTALLED => 'The OpenAnonymiser ExApp is not installed. Install it through AppAPI.',
		ProbeResult::ERROR_EXAPP_DISABLED => 'The OpenAnonymiser ExApp is installed and disabled. Enable it through AppAPI.',
	];

	/**
	 * Constructor.
	 *
	 * @param AnonymisationBackendPlatform      $platform      App manager, app config, cache factory and HTTP clients.
	 * @param AnonymisationBackendCollaborators $collaborators File settings, logger and connection reporter.
	 */
	public function __construct(
		AnonymisationBackendPlatform $platform,
		AnonymisationBackendCollaborators $collaborators,
	) {
		$this->appManager          = $platform->appManager;
		$this->appConfig           = $platform->appConfig;
		$this->clientService       = $platform->clientService;
		$this->fileSettingsHandler = $collaborators->fileSettingsHandler;
		$this->logger              = $collaborators->logger;
		$this->connectionReporter  = $collaborators->connectionReporter;

		$cache = null;
		try {
			$cache = $platform->cacheFactory->createDistributed('openregister_anon_probe');
		} catch (Throwable $e) {
			$this->logger->warning('[AnonymisationBackendService] cache backend unavailable: ' . $e->getMessage());
		}

		$this->probeCache = new ProbeResultCache(cache: $cache, appConfig: $this->appConfig);
	}//end __construct()

	/**
	 * Resolve the full backend selection state.
	 *
	 * @return BackendState The fully-resolved state.
	 */
	public function getState(): BackendState {
		$settings = $this->fileSettingsHandler->getFileSettingsOnly();

		return $this->resolveState(
			enabled: (bool)($settings['entityRecognitionEnabled'] ?? false),
			stored: (string)($settings['entityRecognitionMethod'] ?? BackendState::METHOD_AUTO)
		);
	}//end getState()

	/**
	 * The backend selection state for the stored settings.
	 *
	 * @param bool   $enabled Whether entity recognition is enabled.
	 * @param string $stored  The stored entity-recognition method.
	 *
	 * @return BackendState The fully-resolved state.
	 */
	private function resolveState(bool $enabled, string $stored): BackendState {
		$backends = $this->atomicBackends();

		// Hybrid availability is the logical AND of its composed backends.
		$backends[BackendState::METHOD_HYBRID] = $this->buildHybridInfo(backends: $backends);

		$activeMethod    = $this->resolveActiveMethod(stored: $stored, backends: $backends);
		$effectiveMethod = BackendState::METHOD_REGEX;
		if ($enabled === true) {
			$effectiveMethod = $this->resolveEffectiveMethod(activeMethod: $activeMethod, backends: $backends);
		}

		return new BackendState(
			entityRecognitionEnabled: $enabled,
			activeMethod: $activeMethod,
			effectiveMethod: $effectiveMethod,
			backends: $backends,
		);
	}//end resolveState()

	/**
	 * Probe the atomic backends (cached) and build their info records.
	 *
	 * @return array<string, mixed> The info record per atomic method.
	 */
	private function atomicBackends(): array {
		$probeMethods = [
			BackendState::METHOD_REGEX,
			BackendState::METHOD_PRESIDIO,
			BackendState::METHOD_OPENANONYMISER,
			BackendState::METHOD_LLM,
		];

		$backends = [];
		foreach ($probeMethods as $method) {
			$backends[$method] = $this->buildBackendInfo(method: $method, probe: $this->probe(method: $method));
		}

		return $backends;
	}//end atomicBackends()

	/**
	 * Issue a fresh probe for a single method, bypassing and refreshing the cache.
	 *
	 * An OpenAnonymiser test also tells integriq's connection registry what it found.
	 *
	 * @param string $method One of BackendState::METHODS.
	 *
	 * @return ProbeResult The fresh probe result.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
	 */
	public function testConnection(string $method): ProbeResult {
		$result = $this->freshProbe(method: $method);
		$this->probeCache->write(method: $method, result: $result);

		if ($method === BackendState::METHOD_OPENANONYMISER) {
			$this->reportOpenAnonymiser(result: $result);
		}

		return $result;
	}//end testConnection()

	/**
	 * Tell integriq what an OpenAnonymiser connection test found.
	 *
	 * Only the explicit test reports. The cached probe behind getState() runs on
	 * page loads, and a report there would write integriq's row on every one.
	 *
	 * @param ProbeResult $result The fresh probe result.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
	 */
	private function reportOpenAnonymiser(ProbeResult $result): void {
		$this->connectionReporter->report(
			key: 'anonymiser',
			status: $this->openAnonymiserStatus(result: $result),
			message: $this->openAnonymiserMessage(result: $result)
		);
	}//end reportOpenAnonymiser()

	/**
	 * The connection status an OpenAnonymiser test found: configured, unconfigured (a next step is known) or error.
	 *
	 * @param ProbeResult $result The fresh probe result.
	 *
	 * @return string The connection status.
	 */
	private function openAnonymiserStatus(ProbeResult $result): string {
		if ($result->reachable === true) {
			return 'configured';
		}

		if ($result->error !== null && isset(self::OPENANONYMISER_NEXT_STEPS[$result->error]) === true) {
			return 'unconfigured';
		}

		return 'error';
	}//end openAnonymiserStatus()

	/**
	 * What an OpenAnonymiser test found, and the next step when one is known.
	 *
	 * @param ProbeResult $result The fresh probe result.
	 *
	 * @return string The message.
	 */
	private function openAnonymiserMessage(ProbeResult $result): string {
		if ($result->reachable === true) {
			return 'The OpenAnonymiser ExApp is enabled and detected through AppAPI.';
		}

		if ($result->error !== null && isset(self::OPENANONYMISER_NEXT_STEPS[$result->error]) === true) {
			return self::OPENANONYMISER_NEXT_STEPS[$result->error];
		}

		return 'The OpenAnonymiser connection test failed: ' . ($result->error ?? 'unknown error') . '.';
	}//end openAnonymiserMessage()

	/**
	 * Probe a method, consuming the cache when a fresh-enough entry exists.
	 *
	 * @param string $method One of BackendState::METHODS.
	 *
	 * @return ProbeResult The (possibly cached) probe result.
	 */
	public function probe(string $method): ProbeResult {
		$cached = $this->probeCache->read(method: $method);
		if ($cached !== null) {
			return $cached;
		}

		$result = $this->freshProbe(method: $method);
		$this->probeCache->write(method: $method, result: $result);

		return $result;
	}//end probe()

	/**
	 * Produce a fresh probe result for a method, ignoring the cache.
	 *
	 * @param string $method One of BackendState::METHODS.
	 *
	 * @return ProbeResult The fresh probe result.
	 */
	private function freshProbe(string $method): ProbeResult {
		switch ($method) {
			case BackendState::METHOD_REGEX:
				return new ProbeResult(reachable: true, latencyMs: 0, error: null, probedAt: $this->now());
			case BackendState::METHOD_OPENANONYMISER:
				return $this->detectOpenAnonymiser();
			case BackendState::METHOD_PRESIDIO:
				return $this->probePresidio();
			case BackendState::METHOD_HYBRID:
				return $this->probeHybrid();
			case BackendState::METHOD_LLM:
			default:
				// LLM is deferred: report not-configured. Unknown methods behave the same.
				return $this->unreachable(error: ProbeResult::ERROR_NOT_CONFIGURED);
		}//end switch
	}//end freshProbe()

	/**
	 * Detect the OpenAnonymiser ExApp via AppAPI / IAppManager.
	 *
	 * Prefers the full `openanonymiser` variant over `openanonymiser_light` when
	 * both are enabled. Issues no external HTTP request.
	 *
	 * @return ProbeResult Reachable when a healthy ExApp is detected, else an error code.
	 */
	private function detectOpenAnonymiser(): ProbeResult {
		// AppAPI must be present for any ExApp detection.
		if ($this->appManager->isEnabledForUser(self::APP_API) === false) {
			return $this->unreachable(error: ProbeResult::ERROR_APPAPI_MISSING);
		}

		$fullEnabled = $this->appManager->isEnabledForUser(self::EXAPP_FULL);
		$lightEnabled = $this->appManager->isEnabledForUser(self::EXAPP_LIGHT);

		if ($fullEnabled === true || $lightEnabled === true) {
			if ($fullEnabled === true && $lightEnabled === true) {
				$this->logger->debug('[AnonymisationBackendService] both OpenAnonymiser variants enabled; using full, light also-detected');
			}

			return new ProbeResult(reachable: true, latencyMs: 0, error: null, probedAt: $this->now());
		}

		return $this->unreachable(error: $this->exAppAbsenceError());
	}//end detectOpenAnonymiser()

	/**
	 * Why no OpenAnonymiser variant is enabled: installed-but-disabled, or not installed.
	 *
	 * @return string ProbeResult::ERROR_EXAPP_DISABLED or ProbeResult::ERROR_EXAPP_NOT_INSTALLED.
	 */
	private function exAppAbsenceError(): string {
		$anyInstalled = ($this->appManager->isInstalled(self::EXAPP_FULL) === true
			|| $this->appManager->isInstalled(self::EXAPP_LIGHT) === true);

		if ($anyInstalled === true) {
			return ProbeResult::ERROR_EXAPP_DISABLED;
		}

		return ProbeResult::ERROR_EXAPP_NOT_INSTALLED;
	}//end exAppAbsenceError()

	/**
	 * Resolve the app id of the active OpenAnonymiser ExApp.
	 *
	 * Prefers the full `openanonymiser` variant over `openanonymiser_light`.
	 * Returns null when AppAPI is absent or no variant is enabled.
	 *
	 * @return string|null The ExApp app id, or null.
	 */
	public function resolveActiveExAppId(): ?string {
		if ($this->appManager->isEnabledForUser(self::APP_API) === false) {
			return null;
		}

		if ($this->appManager->isEnabledForUser(self::EXAPP_FULL) === true) {
			return self::EXAPP_FULL;
		}

		if ($this->appManager->isEnabledForUser(self::EXAPP_LIGHT) === true) {
			return self::EXAPP_LIGHT;
		}

		return null;
	}//end resolveActiveExAppId()

	/**
	 * Issue a signed request to the active OpenAnonymiser ExApp via AppAPI.
	 *
	 * Uses `OCA\AppAPI\PublicFunctions::exAppRequest()`, resolved lazily so
	 * OpenRegister carries no hard dependency on AppAPI (ADR-017). ExApps reject
	 * unsigned requests, so a plain HTTP call to the internal host would fail —
	 * routing and auth headers are handled by AppAPI.
	 *
	 * @param AnonymiserCall $call The ExApp route (e.g. `/api/v1/analyze`), the request body parameters and the HTTP method.
	 *
	 * @return array<string, mixed>|null Decoded JSON response, or null on failure.
	 *
	 * @throws AnalyzeRequestRejectedException When the ExApp answers 4xx: it was reached and refused the request.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
	 */
	public function requestOpenAnonymiser(AnonymiserCall $call): ?array {
		return $this->sendToExApp(call: $call);
	}//end requestOpenAnonymiser()

	/**
	 * Issue one signed request to the active OpenAnonymiser ExApp via AppAPI.
	 *
	 * @param AnonymiserCall $call The route, body parameters and HTTP method.
	 *
	 * @return array<string, mixed>|null Decoded JSON response, or null on failure.
	 *
	 * @throws AnalyzeRequestRejectedException When the ExApp answers 4xx: it was reached and refused the request.
	 */
	private function sendToExApp(AnonymiserCall $call): ?array {
		$appId = $this->resolveActiveExAppId();
		if ($appId === null) {
			return null;
		}

		// Avoid a compile-time dependency on the optional AppAPI app.
		$publicFunctionsClass = 'OCA\\AppAPI\\PublicFunctions';
		if (class_exists($publicFunctionsClass) === false) {
			$this->logger->warning('[AnonymisationBackendService] AppAPI PublicFunctions unavailable; cannot reach ExApp');
			return null;
		}

		try {
			// phpcs:ignore CustomSniffs.Nextcloud.NoServiceLocator.GlobalContainerLookup -- Optional AppAPI PublicFunctions: guarded by class_exists and try/catch, absent unless the ExApp stack is installed.
			$publicFunctions = Server::get($publicFunctionsClass);
			$response = $publicFunctions->exAppRequest($appId, $call->route, null, $call->method, $call->params);

			if (is_array($response) === true) {
				// ExAppRequest returns an array only to signal an error.
				$this->logger->error('[AnonymisationBackendService] ExApp request error: ' . ($response['error'] ?? 'unknown'));
				return null;
			}

			if (($response instanceof IResponse) === false) {
				return null;
			}

			return $this->decodeExAppResponse(response: $response, appId: $appId);
		} catch (AnalyzeRequestRejectedException $e) {
			throw $e;
		} catch (Throwable $e) {
			$this->logger->error('[AnonymisationBackendService] ExApp request to ' . $appId . ' failed: ' . $e->getMessage());
			return null;
		}//end try
	}//end sendToExApp()

	/**
	 * Decode an ExApp response, or refuse it when the ExApp rejected the request.
	 *
	 * A 4xx means the ExApp was reached and refused the request (or#4115), so it
	 * is thrown rather than read as an unreachable ExApp: the caller must neither
	 * retry another transport nor fall back to regex.
	 *
	 * @param IResponse $response The ExApp response.
	 * @param string    $appId    The ExApp id, for the log line.
	 *
	 * @return array<string, mixed>|null The decoded body, or null on a non-2xx or non-JSON answer.
	 *
	 * @throws AnalyzeRequestRejectedException When the ExApp answers 4xx.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The exception's own status predicate.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
	 */
	private function decodeExAppResponse(IResponse $response, string $appId): ?array {
		$status = $response->getStatusCode();
		if (AnalyzeRequestRejectedException::isRequestError(status: $status) === true) {
			$body = $response->getBody();
			$detail = '';
			if (is_string($body) === true) {
				$detail = $body;
			}

			throw new AnalyzeRequestRejectedException(service: 'OpenAnonymiser', status: $status, detail: $detail);
		}

		if ($status < 200 || $status >= 300) {
			$this->logger->error('[AnonymisationBackendService] ExApp ' . $appId . ' returned HTTP ' . $status);
			return null;
		}

		$decoded = json_decode((string)$response->getBody(), true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return null;
	}//end decodeExAppResponse()

	/**
	 * Probe the configured Presidio endpoint over HTTP.
	 *
	 * @return ProbeResult Reachable with latency on success, else a mapped error code.
	 */
	private function probePresidio(): ProbeResult {
		$settings = $this->fileSettingsHandler->getFileSettingsOnly();
		$endpoint = trim((string)($settings['presidioApiEndpoint'] ?? ''));

		if ($endpoint === '') {
			return $this->unreachable(error: ProbeResult::ERROR_NOT_CONFIGURED);
		}

		$start = microtime(true);
		try {
			$response = $this->requestPresidioHealth(endpoint: $endpoint);
			$latency  = (int)round((microtime(true) - $start) * 1000);

			return $this->presidioHealthResult(latency: $latency, statusCode: $response->getStatusCode());
		} catch (Throwable $e) {
			return $this->unreachable(error: ProbeResult::errorFromHttpMessage(message: $e->getMessage()));
		}
	}//end probePresidio()

	/**
	 * Ask the Presidio endpoint for its health.
	 *
	 * Follows the instance's outbound rules: a Presidio on a local or private
	 * address answers only when the administrator set `allow_local_remote_servers`.
	 *
	 * @param string $endpoint The configured endpoint, trimmed and non-empty.
	 *
	 * @return IResponse The health answer.
	 */
	private function requestPresidioHealth(string $endpoint): IResponse {
		$client = $this->clientService->newClient();

		return $client->get(
			rtrim($endpoint, '/') . '/health',
			[
				'timeout' => 10,
				'connect_timeout' => 5,
			]
		);
	}//end requestPresidioHealth()

	/**
	 * Read a Presidio health answer: reachable on a 2xx, else a 5xx or 4xx error, with the latency either way.
	 *
	 * @param int $latency    The round trip in milliseconds.
	 * @param int $statusCode The HTTP status of the answer.
	 *
	 * @return ProbeResult The probe result.
	 */
	private function presidioHealthResult(int $latency, int $statusCode): ProbeResult {
		if ($statusCode >= 200 && $statusCode < 300) {
			return new ProbeResult(reachable: true, latencyMs: $latency, error: null, probedAt: $this->now());
		}

		if ($statusCode >= 500) {
			return $this->unreachable(error: ProbeResult::ERROR_HTTP_5XX, latencyMs: $latency);
		}

		return $this->unreachable(error: ProbeResult::ERROR_HTTP_4XX, latencyMs: $latency);
	}//end presidioHealthResult()

	/**
	 * An unreachable probe result, probed now.
	 *
	 * @param string   $error     One of the ProbeResult::ERROR_* codes.
	 * @param int|null $latencyMs The round trip when the backend answered, else null.
	 *
	 * @return ProbeResult The probe result.
	 */
	private function unreachable(string $error, ?int $latencyMs = null): ProbeResult {
		return new ProbeResult(
			reachable: false,
			latencyMs: $latencyMs,
			error: $error,
			probedAt: $this->now(),
		);
	}//end unreachable()

	/**
	 * Compose a hybrid probe result from its constituent backends.
	 *
	 * @return ProbeResult Reachable only when all composed backends are reachable.
	 */
	private function probeHybrid(): ProbeResult {
		$regex = $this->probe(method: BackendState::METHOD_REGEX);
		$presidio = $this->probe(method: BackendState::METHOD_PRESIDIO);
		$openAnonymiser = $this->probe(method: BackendState::METHOD_OPENANONYMISER);

		$reachable = ($regex->reachable === true && $presidio->reachable === true && $openAnonymiser->reachable === true);

		$error = null;
		if ($reachable === false) {
			$error = ($presidio->error ?? $openAnonymiser->error ?? ProbeResult::ERROR_NOT_CONFIGURED);
		}

		return new ProbeResult(reachable: $reachable, latencyMs: null, error: $error, probedAt: $this->now());
	}//end probeHybrid()

	/**
	 * Build a BackendInfo for an atomic method from its probe result.
	 *
	 * @param string $method The method enum value.
	 * @param ProbeResult $probe The probe result for the method.
	 *
	 * @return BackendInfo The availability/configuration record.
	 */
	private function buildBackendInfo(string $method, ProbeResult $probe): BackendInfo {
		return new BackendInfo(
			name: $method,
			configured: $this->isConfigured(method: $method, probe: $probe),
			probe: $probe,
		);
	}//end buildBackendInfo()

	/**
	 * Build the hybrid BackendInfo from the composed atomic records.
	 *
	 * @param array<string, BackendInfo> $backends The already-built atomic records.
	 *
	 * @return BackendInfo The hybrid availability record.
	 */
	private function buildHybridInfo(array $backends): BackendInfo {
		$regex = $backends[BackendState::METHOD_REGEX];
		$presidio = $backends[BackendState::METHOD_PRESIDIO];
		$openAnonymiser = $backends[BackendState::METHOD_OPENANONYMISER];

		$available = ($regex->available === true && $presidio->available === true && $openAnonymiser->available === true);
		$configured = ($presidio->configured === true && $openAnonymiser->configured === true);

		return new BackendInfo(
			name: BackendState::METHOD_HYBRID,
			configured: $configured,
			probe: new ProbeResult(reachable: $available, latencyMs: null, error: null, probedAt: $this->now()),
		);
	}//end buildHybridInfo()

	/**
	 * Determine whether a method has usable configuration.
	 *
	 * @param string $method The method enum value.
	 * @param ProbeResult $probe The probe result for the method.
	 *
	 * @return bool True when the backend is configured.
	 */
	private function isConfigured(string $method, ProbeResult $probe): bool {
		switch ($method) {
			case BackendState::METHOD_REGEX:
				return true;
			case BackendState::METHOD_OPENANONYMISER:
				// An installed ExApp counts as configured; "not configured" maps to absent/AppAPI errors.
				return ($probe->error === null
					|| $probe->error === ProbeResult::ERROR_HTTP_5XX
					|| $probe->error === ProbeResult::ERROR_HTTP_4XX);

			case BackendState::METHOD_PRESIDIO:
				// Configured when an endpoint is set (i.e. the probe was not skipped as not-configured).
				return ($probe->error !== ProbeResult::ERROR_NOT_CONFIGURED);
			case BackendState::METHOD_LLM:
			default:
				return false;
		}//end switch
	}//end isConfigured()

	/**
	 * Resolve the active method, applying the first-run auto-select rule.
	 *
	 * @param string $stored The stored method (may be the `auto` sentinel).
	 * @param array<string, BackendInfo> $backends The per-method availability records.
	 *
	 * @return string The resolved active method enum value.
	 */
	private function resolveActiveMethod(string $stored, array $backends): string {
		if ($stored === BackendState::METHOD_AUTO) {
			// Auto-select a detected OpenAnonymiser ExApp on first run, else fall back to regex.
			if (($backends[BackendState::METHOD_OPENANONYMISER]->available ?? false) === true) {
				return BackendState::METHOD_OPENANONYMISER;
			}

			return BackendState::METHOD_REGEX;
		}

		// Operator intent wins. Guard against an unknown stored value.
		if (in_array($stored, BackendState::METHODS, true) === true) {
			return $stored;
		}

		return BackendState::METHOD_REGEX;
	}//end resolveActiveMethod()

	/**
	 * Apply the effectiveMethod precedence rule while recognition is enabled.
	 *
	 * @param string $activeMethod The resolved active method.
	 * @param array<string, BackendInfo> $backends The per-method availability records.
	 *
	 * @return string The method that will actually be used.
	 */
	private function resolveEffectiveMethod(string $activeMethod, array $backends): string {
		if ($activeMethod === BackendState::METHOD_REGEX) {
			return BackendState::METHOD_REGEX;
		}

		$info = ($backends[$activeMethod] ?? null);
		if ($info !== null && $info->available === true && $info->configured === true) {
			return $activeMethod;
		}

		return BackendState::METHOD_REGEX;
	}//end resolveEffectiveMethod()

	/**
	 * Current time as an ISO-8601 string.
	 *
	 * @return string The timestamp.
	 */
	private function now(): string {
		return gmdate('c');
	}//end now()
}//end class
