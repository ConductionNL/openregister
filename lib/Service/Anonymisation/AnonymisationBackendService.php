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

use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
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
class AnonymisationBackendService
{
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
     * IAppConfig key holding the probe-cache TTL in seconds.
     */
    private const TTL_CONFIG_KEY = 'anonymisation.probe_cache_ttl';

    /**
     * Default probe-cache TTL in seconds.
     */
    private const TTL_DEFAULT = 60;

    /**
     * Minimum allowed probe-cache TTL in seconds.
     */
    private const TTL_MIN = 10;

    /**
     * Maximum allowed probe-cache TTL in seconds.
     */
    private const TTL_MAX = 600;

    /**
     * Distributed probe-result cache, or null when no backend is available.
     *
     * @var ICache|null
     */
    private ?ICache $cache = null;

    /**
     * Constructor.
     *
     * @param IAppManager         $appManager          App manager used for ExApp detection.
     * @param IAppConfig          $appConfig           App configuration store.
     * @param ICacheFactory       $cacheFactory        Factory for the distributed probe cache.
     * @param IClientService      $clientService       HTTP client factory for endpoint probes.
     * @param FileSettingsHandler $fileSettingsHandler Reads the stored entity-recognition settings.
     * @param LoggerInterface     $logger              Logger.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly IAppConfig $appConfig,
        ICacheFactory $cacheFactory,
        private readonly IClientService $clientService,
        private readonly FileSettingsHandler $fileSettingsHandler,
        private readonly LoggerInterface $logger,
    ) {
        try {
            $this->cache = $cacheFactory->createDistributed('openregister_anon_probe');
        } catch (Throwable $e) {
            $this->logger->warning('[AnonymisationBackendService] cache backend unavailable: '.$e->getMessage());
            $this->cache = null;
        }
    }//end __construct()

    /**
     * Resolve the full backend selection state.
     *
     * @return BackendState The fully-resolved state.
     */
    public function getState(): BackendState
    {
        $settings = $this->fileSettingsHandler->getFileSettingsOnly();
        $enabled  = (bool) ($settings['entityRecognitionEnabled'] ?? false);
        $stored   = (string) ($settings['entityRecognitionMethod'] ?? BackendState::METHOD_AUTO);

        // Probe the atomic backends (cached) and build their info records.
        $backends = [];
        foreach ([BackendState::METHOD_REGEX, BackendState::METHOD_PRESIDIO, BackendState::METHOD_OPENANONYMISER, BackendState::METHOD_LLM] as $method) {
            $backends[$method] = $this->buildBackendInfo(method: $method, probe: $this->probe(method: $method));
        }

        // Hybrid availability is the logical AND of its composed backends.
        $backends[BackendState::METHOD_HYBRID] = $this->buildHybridInfo(backends: $backends);

        $activeMethod    = $this->resolveActiveMethod(stored: $stored, backends: $backends);
        $effectiveMethod = $this->resolveEffectiveMethod(enabled: $enabled, activeMethod: $activeMethod, backends: $backends);

        return new BackendState(
            entityRecognitionEnabled: $enabled,
            activeMethod: $activeMethod,
            effectiveMethod: $effectiveMethod,
            backends: $backends,
        );
    }//end getState()

    /**
     * Issue a fresh probe for a single method, bypassing and refreshing the cache.
     *
     * @param string $method One of BackendState::METHODS.
     *
     * @return ProbeResult The fresh probe result.
     */
    public function testConnection(string $method): ProbeResult
    {
        $result = $this->freshProbe(method: $method);
        $this->writeCache(method: $method, result: $result);

        return $result;
    }//end testConnection()

    /**
     * Probe a method, consuming the cache when a fresh-enough entry exists.
     *
     * @param string $method One of BackendState::METHODS.
     *
     * @return ProbeResult The (possibly cached) probe result.
     */
    public function probe(string $method): ProbeResult
    {
        $cached = $this->readCache(method: $method);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->freshProbe(method: $method);
        $this->writeCache(method: $method, result: $result);

        return $result;
    }//end probe()

    /**
     * Produce a fresh probe result for a method, ignoring the cache.
     *
     * @param string $method One of BackendState::METHODS.
     *
     * @return ProbeResult The fresh probe result.
     */
    private function freshProbe(string $method): ProbeResult
    {
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
                return new ProbeResult(
                    reachable: false,
                    latencyMs: null,
                    error: ProbeResult::ERROR_NOT_CONFIGURED,
                    probedAt: $this->now(),
                );
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
    private function detectOpenAnonymiser(): ProbeResult
    {
        // AppAPI must be present for any ExApp detection.
        if ($this->appManager->isEnabledForUser(self::APP_API) === false) {
            return new ProbeResult(
                reachable: false,
                latencyMs: null,
                error: ProbeResult::ERROR_APPAPI_MISSING,
                probedAt: $this->now(),
            );
        }

        $fullEnabled  = $this->appManager->isEnabledForUser(self::EXAPP_FULL);
        $lightEnabled = $this->appManager->isEnabledForUser(self::EXAPP_LIGHT);

        if ($fullEnabled === true || $lightEnabled === true) {
            if ($fullEnabled === true && $lightEnabled === true) {
                $this->logger->debug('[AnonymisationBackendService] both OpenAnonymiser variants enabled; using full, light also-detected');
            }

            return new ProbeResult(reachable: true, latencyMs: 0, error: null, probedAt: $this->now());
        }

        // Not enabled: distinguish installed-but-disabled from not-installed.
        $anyInstalled = ($this->appManager->isInstalled(self::EXAPP_FULL) === true
            || $this->appManager->isInstalled(self::EXAPP_LIGHT) === true);

        return new ProbeResult(
            reachable: false,
            latencyMs: null,
            error: ($anyInstalled === true ? ProbeResult::ERROR_EXAPP_DISABLED : ProbeResult::ERROR_EXAPP_NOT_INSTALLED),
            probedAt: $this->now(),
        );
    }//end detectOpenAnonymiser()

    /**
     * Resolve the app id of the active OpenAnonymiser ExApp.
     *
     * Prefers the full `openanonymiser` variant over `openanonymiser_light`.
     * Returns null when AppAPI is absent or no variant is enabled.
     *
     * @return string|null The ExApp app id, or null.
     */
    public function resolveActiveExAppId(): ?string
    {
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
     * @param string               $route  ExApp route (e.g. `/api/v1/analyze`).
     * @param array<string, mixed> $params Request body parameters.
     * @param string               $method HTTP method (default POST).
     *
     * @return array<string, mixed>|null Decoded JSON response, or null on failure.
     */
    public function requestOpenAnonymiser(string $route, array $params, string $method = 'POST'): ?array
    {
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
            $publicFunctions = Server::get($publicFunctionsClass);
            $response        = $publicFunctions->exAppRequest($appId, $route, null, $method, $params);

            if (is_array($response) === true) {
                // exAppRequest returns an array only to signal an error.
                $this->logger->error('[AnonymisationBackendService] ExApp request error: '.($response['error'] ?? 'unknown'));
                return null;
            }

            if (($response instanceof IResponse) === false) {
                return null;
            }

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->logger->error('[AnonymisationBackendService] ExApp '.$appId.' returned HTTP '.$status);
                return null;
            }

            $decoded = json_decode((string) $response->getBody(), true);

            return (is_array($decoded) === true ? $decoded : null);
        } catch (Throwable $e) {
            $this->logger->error('[AnonymisationBackendService] ExApp request to '.$appId.' failed: '.$e->getMessage());
            return null;
        }//end try
    }//end requestOpenAnonymiser()

    /**
     * Probe the configured Presidio endpoint over HTTP.
     *
     * @return ProbeResult Reachable with latency on success, else a mapped error code.
     */
    private function probePresidio(): ProbeResult
    {
        $settings = $this->fileSettingsHandler->getFileSettingsOnly();
        $endpoint = trim((string) ($settings['presidioApiEndpoint'] ?? ''));

        if ($endpoint === '') {
            return new ProbeResult(
                reachable: false,
                latencyMs: null,
                error: ProbeResult::ERROR_NOT_CONFIGURED,
                probedAt: $this->now(),
            );
        }

        $start = microtime(true);
        try {
            $client   = $this->clientService->newClient();
            $response = $client->get(
                rtrim($endpoint, '/').'/health',
                [
                    'timeout'         => 10,
                    'connect_timeout' => 5,
                    'nextcloud'       => ['allow_local_address' => true],
                ]
            );

            $latency    = (int) round((microtime(true) - $start) * 1000);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return new ProbeResult(reachable: true, latencyMs: $latency, error: null, probedAt: $this->now());
            }

            return new ProbeResult(
                reachable: false,
                latencyMs: $latency,
                error: ($statusCode >= 500 ? ProbeResult::ERROR_HTTP_5XX : ProbeResult::ERROR_HTTP_4XX),
                probedAt: $this->now(),
            );
        } catch (Throwable $e) {
            return new ProbeResult(
                reachable: false,
                latencyMs: null,
                error: $this->mapHttpException(message: $e->getMessage()),
                probedAt: $this->now(),
            );
        }//end try
    }//end probePresidio()

    /**
     * Compose a hybrid probe result from its constituent backends.
     *
     * @return ProbeResult Reachable only when all composed backends are reachable.
     */
    private function probeHybrid(): ProbeResult
    {
        $regex          = $this->probe(method: BackendState::METHOD_REGEX);
        $presidio       = $this->probe(method: BackendState::METHOD_PRESIDIO);
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
     * @param string      $method The method enum value.
     * @param ProbeResult $probe  The probe result for the method.
     *
     * @return BackendInfo The availability/configuration record.
     */
    private function buildBackendInfo(string $method, ProbeResult $probe): BackendInfo
    {
        return new BackendInfo(
            name: $method,
            available: $probe->reachable,
            configured: $this->isConfigured(method: $method, probe: $probe),
            lastProbedAt: $probe->probedAt,
            latencyMs: $probe->latencyMs,
        );
    }//end buildBackendInfo()

    /**
     * Build the hybrid BackendInfo from the composed atomic records.
     *
     * @param array<string, BackendInfo> $backends The already-built atomic records.
     *
     * @return BackendInfo The hybrid availability record.
     */
    private function buildHybridInfo(array $backends): BackendInfo
    {
        $regex          = $backends[BackendState::METHOD_REGEX];
        $presidio       = $backends[BackendState::METHOD_PRESIDIO];
        $openAnonymiser = $backends[BackendState::METHOD_OPENANONYMISER];

        $available  = ($regex->available === true && $presidio->available === true && $openAnonymiser->available === true);
        $configured = ($presidio->configured === true && $openAnonymiser->configured === true);

        return new BackendInfo(
            name: BackendState::METHOD_HYBRID,
            available: $available,
            configured: $configured,
            lastProbedAt: $this->now(),
            latencyMs: null,
        );
    }//end buildHybridInfo()

    /**
     * Determine whether a method has usable configuration.
     *
     * @param string      $method The method enum value.
     * @param ProbeResult $probe  The probe result for the method.
     *
     * @return bool True when the backend is configured.
     */
    private function isConfigured(string $method, ProbeResult $probe): bool
    {
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
     * @param string                      $stored   The stored method (may be the `auto` sentinel).
     * @param array<string, BackendInfo>  $backends The per-method availability records.
     *
     * @return string The resolved active method enum value.
     */
    private function resolveActiveMethod(string $stored, array $backends): string
    {
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
     * Apply the effectiveMethod precedence rule.
     *
     * @param bool                        $enabled      Whether recognition is enabled.
     * @param string                      $activeMethod The resolved active method.
     * @param array<string, BackendInfo>  $backends     The per-method availability records.
     *
     * @return string The method that will actually be used.
     */
    private function resolveEffectiveMethod(bool $enabled, string $activeMethod, array $backends): string
    {
        if ($enabled === false) {
            return BackendState::METHOD_REGEX;
        }

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
     * Map an HTTP client exception message to a probe error code.
     *
     * @param string $message The exception message.
     *
     * @return string One of the ProbeResult::ERROR_* codes.
     */
    private function mapHttpException(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'timed out') === true || str_contains($lower, 'timeout') === true) {
            return ProbeResult::ERROR_TIMEOUT;
        }

        if (str_contains($lower, 'could not resolve') === true || str_contains($lower, 'name or service not known') === true) {
            return ProbeResult::ERROR_DNS_ERROR;
        }

        return ProbeResult::ERROR_CONNECT_REFUSED;
    }//end mapHttpException()

    /**
     * Resolve the probe-cache TTL, clamped to the allowed range.
     *
     * @return int The TTL in seconds.
     */
    private function resolveTtl(): int
    {
        $ttl = $this->appConfig->getValueInt('openregister', self::TTL_CONFIG_KEY, self::TTL_DEFAULT);

        return max(self::TTL_MIN, min(self::TTL_MAX, $ttl));
    }//end resolveTtl()

    /**
     * Read a cached probe result for a method, or null on miss / disabled cache.
     *
     * @param string $method The method enum value.
     *
     * @return ProbeResult|null The cached result, or null.
     */
    private function readCache(string $method): ?ProbeResult
    {
        if ($this->cache === null) {
            return null;
        }

        $blob = $this->cache->get($method);
        if (is_string($blob) === false) {
            return null;
        }

        $data = json_decode($blob, true);
        if (is_array($data) === false) {
            return null;
        }

        return new ProbeResult(
            reachable: (bool) ($data['reachable'] ?? false),
            latencyMs: (($data['latencyMs'] ?? null) === null ? null : (int) $data['latencyMs']),
            error: (($data['error'] ?? null) === null ? null : (string) $data['error']),
            probedAt: (string) ($data['probedAt'] ?? $this->now()),
        );
    }//end readCache()

    /**
     * Write a probe result to the cache with the configured TTL.
     *
     * @param string      $method The method enum value.
     * @param ProbeResult $result The result to cache.
     *
     * @return void
     */
    private function writeCache(string $method, ProbeResult $result): void
    {
        if ($this->cache === null) {
            return;
        }

        $this->cache->set($method, json_encode($result->jsonSerialize()), $this->resolveTtl());
    }//end writeCache()

    /**
     * Current time as an ISO-8601 string.
     *
     * @return string The timestamp.
     */
    private function now(): string
    {
        return gmdate('c');
    }//end now()
}//end class
