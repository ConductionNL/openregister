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
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\ITempManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs declarative health checks and resolves the response status + code.
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-2.1
 */
class HealthCheckExecutor
{
    private const STATUS_OK = 'ok';

    private const STATUS_DEGRADED = 'degraded';

    private const STATUS_ERROR = 'error';

    /**
     * Constructor.
     *
     * @param IDBConnection      $db          Database connection (database check).
     * @param ITempManager       $tempManager Temp-file manager (filesystem check).
     * @param IAppManager        $appManager  App manager (appEnabled check).
     * @param IAppConfig         $appConfig   App config (appConfig check).
     * @param ContainerInterface $container   DI container (lazy orAvailable + provider lookup).
     * @param LoggerInterface    $logger      PSR logger (exception messages logged, never returned).
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly ITempManager $tempManager,
        private readonly IAppManager $appManager,
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger
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
    public function execute(ObservabilityManifest $manifest): HealthResult
    {
        $checks      = [];
        $worstStatus = self::STATUS_OK;

        foreach ($manifest->checks as $descriptor) {
            [$ok, $message]          = $this->runCheck(appId: $manifest->appId, descriptor: $descriptor);
            $checks[$descriptor->id] = $this->formatCheckResult(ok: $ok, message: $message);

            if ($ok === false) {
                $worstStatus = $this->escalate(current: $worstStatus, severity: $descriptor->severity);
            }
        }

        // Merge IHealthCheckProvider escape-hatch results.
        foreach ($this->resolveProviderResults(appId: $manifest->appId) as $id => $result) {
            $ok       = ($result['ok'] ?? false) === true;
            $severity = ($result['severity'] ?? 'critical');
            if (in_array($severity, HealthCheckDescriptor::SEVERITIES, true) === false) {
                $severity = 'critical';
            }

            $message     = (string) ($result['message'] ?? '');
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
     * @param string                $appId      Calling app id.
     * @param HealthCheckDescriptor $descriptor The check.
     *
     * @return array{0: bool, 1: string} [ok, generic failure message]
     */
    private function runCheck(string $appId, HealthCheckDescriptor $descriptor): array
    {
        try {
            switch ($descriptor->type) {
                case 'database':
                    return $this->checkDatabase();
                case 'filesystem':
                    return $this->checkFilesystem();
                case 'appEnabled':
                    return $this->checkAppEnabled(app: (string) $descriptor->app);
                case 'appConfig':
                    return $this->checkAppConfig(appId: $appId, key: (string) $descriptor->key, assert: (string) $descriptor->assert);
                case 'orAvailable':
                    return $this->checkOrAvailable();
                default:
                    return [false, 'unknown check'];
            }
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
     * @param bool   $ok      Whether the check passed.
     * @param string $message Generic failure message (only used on failure).
     *
     * @return string "ok" or "failed[: message]".
     */
    private function formatCheckResult(bool $ok, string $message): string
    {
        if ($ok === true) {
            return self::STATUS_OK;
        }

        if ($message === '') {
            return 'failed';
        }

        return 'failed: '.$message;
    }//end formatCheckResult()

    /**
     * `SELECT 1` round-trip.
     *
     * @return array{0: bool, 1: string}
     */
    private function checkDatabase(): array
    {
        $result = $this->db->executeQuery('SELECT 1');
        $result->fetch();
        $result->closeCursor();
        return [true, ''];
    }//end checkDatabase()

    /**
     * Write + unlink a temp file.
     *
     * @return array{0: bool, 1: string}
     */
    private function checkFilesystem(): array
    {
        $path = $this->tempManager->getTemporaryFile('-apphost-health');
        if ($path === false || $path === '') {
            return [false, 'temp file unavailable'];
        }

        $bytes = file_put_contents($path, 'ok');
        $ok    = ($bytes !== false);
        // The ITempManager cleans its files on shutdown; unlink eagerly anyway.
        @unlink($path);
        if ($ok === true) {
            return [true, ''];
        }

        return [false, 'temp write failed'];
    }//end checkFilesystem()

    /**
     * App-enabled check.
     *
     * @param string $app Target app id.
     *
     * @return array{0: bool, 1: string}
     */
    private function checkAppEnabled(string $app): array
    {
        $enabled = $this->appManager->isInstalled($app);
        if ($enabled === true) {
            return [true, ''];
        }

        return [false, 'app not enabled'];
    }//end checkAppEnabled()

    /**
     * App-config key assertion (present | nonEmpty).
     *
     * @param string $appId  Calling app id.
     * @param string $key    Config key.
     * @param string $assert present|nonEmpty.
     *
     * @return array{0: bool, 1: string}
     */
    private function checkAppConfig(string $appId, string $key, string $assert): array
    {
        $has = $this->appConfig->hasKey($appId, $key);
        if ($has === false) {
            return [false, 'config key missing'];
        }

        if ($assert === 'nonEmpty') {
            $value = $this->appConfig->getValueString($appId, $key, '');
            if ($value === '') {
                return [false, 'config key empty'];
            }
        }

        return [true, ''];
    }//end checkAppConfig()

    /**
     * OpenRegister availability — resolve ObjectService, non-null.
     *
     * @return array{0: bool, 1: string}
     */
    private function checkOrAvailable(): array
    {
        $service = $this->container->get(\OCA\OpenRegister\Service\ObjectService::class);
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
    private function resolveProviderResults(string $appId): array
    {
        $alias = IHealthCheckProvider::class.'::'.$appId;
        try {
            // Resolve from the CALLING APP's own container — where the app
            // registered its `IHealthCheckProvider::<appId>` alias — not from
            // OpenRegister's container, which cannot see another app's DI
            // registrations (same root cause as the MCP-provider fix, #390).
            $container = $this->resolveAppContainer(appId: $appId);

            if ($container->has($alias) === false) {
                return [];
            }

            $provider = $container->get($alias);
            if (($provider instanceof IHealthCheckProvider) === false) {
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
     * Resolve the calling app's OWN DI container so its
     * `IHealthCheckProvider::<appId>` alias resolves from where the leaf app
     * actually registered it (#390). Fail-soft to OpenRegister's container.
     *
     * @param string $appId The calling app id.
     *
     * @return ContainerInterface The app's own container, or OpenRegister's as a fallback.
     */
    private function resolveAppContainer(string $appId): ContainerInterface
    {
        try {
            // Reaching ANOTHER app's DI container has no OCP equivalent — \OCP\Server::get()
            // resolves the server container only. The sniff says this is removed in NC 34,
            // but it is not: core itself calls it in lib/public/AppFramework/App.php. Scoped
            // ignore rather than a blanket one, so any OTHER legacy accessor still fails.
            // phpcs:ignore CustomSniffs.Nextcloud.NoLegacyServerAccessors.LegacyNamedAccessor
            $appContainer = \OC::$server->getRegisteredAppContainer($appId);
            if ($appContainer instanceof ContainerInterface) {
                return $appContainer;
            }
        } catch (Throwable $e) {
            $this->logger->debug(
                message: sprintf('[AppHost\\Health] no registered app container for "%s": %s', $appId, $e->getMessage()),
                context: ['file' => __FILE__, 'line' => __LINE__, 'app' => $appId]
            );
        }//end try

        return $this->container;
    }//end resolveAppContainer()

    /**
     * Escalate the running status given a failed check's severity.
     *
     * @param string $current  Current worst status.
     * @param string $severity critical|degraded.
     *
     * @return string New worst status.
     */
    private function escalate(string $current, string $severity): string
    {
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
    private function resolveHttpCode(string $status, string $policy): int
    {
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
