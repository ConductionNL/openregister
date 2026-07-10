<?php

/**
 * OpenRegister AppHost — Schedule Manifest Loader
 *
 * Resolves and decodes on-disk AppHost manifests (`src/manifest.json`) and
 * parses their `schedules[]` block into {@see ScheduleManifest} value objects,
 * mirroring the observability engine's {@see \OCA\OpenRegister\AppHost\Observability\ManifestLoader}
 * resolution path. Enumerates every installed app so the reconciler can sweep
 * all on-disk manifest apps each tick.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Scheduling
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

namespace OCA\OpenRegister\AppHost\Scheduling;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Loads on-disk app manifests and parses their `schedules[]` declarations.
 *
 * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
 */
class ScheduleManifestLoader
{
    /**
     * Constructor.
     *
     * @param IAppManager           $appManager App-path + installed-app enumeration.
     * @param CronScheduleEvaluator $cron       Cron parseability validator.
     * @param LoggerInterface       $logger     PSR logger.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly CronScheduleEvaluator $cron,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Load the schedule manifest for a single on-disk app id.
     *
     * @param string $appId The Nextcloud app id.
     *
     * @return ScheduleManifest|null The parsed schedules, or null when the app has no readable manifest.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    public function loadForApp(string $appId): ?ScheduleManifest
    {
        $manifest = $this->loadBundledManifest(appId: $appId);
        if ($manifest === null) {
            return null;
        }

        return ScheduleManifest::fromManifest(applicationId: $appId, manifest: $manifest, cron: $this->cron);
    }//end loadForApp()

    /**
     * Enumerate all installed on-disk apps that declare a `schedules[]` block.
     *
     * @return ScheduleManifest[] One entry per installed app with schedules declared.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    public function loadAllOnDisk(): array
    {
        $manifests = [];

        try {
            $appIds = $this->appManager->getInstalledApps();
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[AppHost\\Scheduling] Failed to enumerate installed apps: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return [];
        }

        foreach ($appIds as $appId) {
            $manifest = $this->loadForApp(appId: (string) $appId);
            if ($manifest === null || $manifest->schedules === []) {
                continue;
            }

            $manifests[] = $manifest;
        }

        return $manifests;
    }//end loadAllOnDisk()

    /**
     * Load + JSON-decode an app's bundled `src/manifest.json`.
     *
     * @param string $appId The Nextcloud app id.
     *
     * @return array<string, mixed>|null Decoded manifest, or null if unreadable.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function loadBundledManifest(string $appId): ?array
    {
        try {
            $appPath = $this->appManager->getAppPath(appId: $appId);
        } catch (Throwable $e) {
            return null;
        }

        $manifestPath = $appPath.'/src/manifest.json';
        if (is_readable($manifestPath) === false) {
            return null;
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, associative: true);
        if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
            $this->logger->warning(
                message: sprintf('[AppHost\\Scheduling] manifest.json for "%s" is invalid JSON', $appId),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }

        return $decoded;
    }//end loadBundledManifest()
}//end class
