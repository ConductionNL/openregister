<?php

/**
 * OpenRegister AppHost — Generic Settings Service
 *
 * Engine-owned generalisation of the per-app `SettingsService`: resolves an
 * app's register/schema binding from IAppConfig, reports whether OpenRegister
 * is available, and (re)imports the app's register JSON / `register.d/`
 * fragments through OpenRegister's ConfigurationService.
 *
 * Parameterised by the CALLING (leaf) app id, supplied via the alias closure
 * in {@see \OCA\OpenRegister\AppHost\Bootstrap::register()}; one instance per
 * leaf app. App-specific config-key maps are overridable via the protected
 * {@see configKeys()} hook.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
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

namespace OCA\OpenRegister\AppHost\Service;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generic settings service for AppHost-adopting apps.
 *
 * Behavioural parity with the bespoke per-app `SettingsService`:
 *   - `getSettings()`   → flat config map + `openregisters` + `isAdmin`.
 *   - `updateSettings()`→ writes configured keys, returns refreshed settings.
 *   - `loadConfiguration()` → imports register JSON via ConfigurationService.
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.1
 */
class AppHostSettingsService
{
    /**
     * Default configuration keys managed for an AppHost leaf app.
     *
     * @var array<int, string>
     */
    protected const DEFAULT_CONFIG_KEYS = ['register'];

    /**
     * Constructor.
     *
     * @param string             $appId        The calling (leaf) app id.
     * @param IAppConfig         $appConfig    App config store.
     * @param IAppManager        $appManager   App manager (OR availability).
     * @param ContainerInterface $container    DI container (lazy OR service resolution).
     * @param IGroupManager      $groupManager Group manager (admin check).
     * @param IUserSession       $userSession  Current user session.
     * @param LoggerInterface    $logger       PSR logger.
     */
    public function __construct(
        private readonly string $appId,
        private readonly IAppConfig $appConfig,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly IGroupManager $groupManager,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Configuration keys this service manages.
     *
     * Overridable hook: an app with extra config keys subclasses this service
     * and widens the list. Defaults to `['register']`, matching the fleet.
     *
     * @return array<int, string>
     */
    protected function configKeys(): array
    {
        return static::DEFAULT_CONFIG_KEYS;
    }//end configKeys()

    /**
     * Whether OpenRegister is installed and enabled.
     *
     * @return bool
     */
    public function isOpenRegisterAvailable(): bool
    {
        return $this->appManager->isInstalled('openregister');
    }//end isOpenRegisterAvailable()

    /**
     * Retrieve all current settings.
     *
     * Returns the configured keys plus `openregisters` (availability) and
     * `isAdmin`. The controller strips admin-sensitive keys for non-admins;
     * this method does not — it is the controller's gating responsibility.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.1
     */
    public function getSettings(): array
    {
        $settings = [];
        foreach ($this->configKeys() as $key) {
            $settings[$key] = $this->appConfig->getValueString($this->appId, $key, '');
        }

        $user    = $this->userSession->getUser();
        $isAdmin = ($user !== null && $this->groupManager->isAdmin($user->getUID()));

        return array_merge(
            $settings,
            [
                'openregisters' => $this->isOpenRegisterAvailable(),
                'isAdmin'       => $isAdmin,
            ]
        );
    }//end getSettings()

    /**
     * Update the managed settings with the provided data.
     *
     * Only keys in {@see configKeys()} are written; unknown keys are ignored.
     *
     * @param array<string, mixed> $data Submitted data.
     *
     * @return array<string, mixed> Refreshed settings.
     *
     * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.1
     */
    public function updateSettings(array $data): array
    {
        foreach ($this->configKeys() as $key) {
            if (isset($data[$key]) === true) {
                $this->appConfig->setValueString($this->appId, $key, (string) $data[$key]);
            }
        }

        return $this->getSettings();
    }//end updateSettings()

    /**
     * Import the app's register JSON via OpenRegister's ConfigurationService.
     *
     * Repair-step-safe: when OR is unavailable this returns a failure result
     * rather than throwing, so a disabled OR degrades instead of fataling.
     *
     * @param bool $force Force re-import even when already configured.
     *
     * @return array<string, mixed> Result with `success`, `message`, and `version`.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     *
     * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.1
     */
    public function loadConfiguration(bool $force=false): array
    {
        if ($this->isOpenRegisterAvailable() === false) {
            $this->logger->warning(sprintf('[AppHost:%s] OpenRegister not available, skipping register initialization', $this->appId));
            return [
                'success' => false,
                'message' => 'OpenRegister is not installed or enabled.',
            ];
        }

        try {
            $configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
            $result = $configurationService->importFromApp(appId: $this->appId, force: $force);

            if (empty($result) === false) {
                $this->logger->info(sprintf('[AppHost:%s] register configuration imported successfully', $this->appId));
                // Stamp the app version the config was imported for, so the admin
                // page can show a REAL up-to-date check (config_version === app version).
                $this->appConfig->setValueString($this->appId, 'config_version', $this->appManager->getAppVersion($this->appId));
                return [
                    'success' => true,
                    'message' => 'Configuration imported successfully.',
                    'version' => ($result['version'] ?? 'unknown'),
                ];
            }

            return [
                'success' => false,
                'message' => 'Import returned an empty result.',
            ];
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('[AppHost:%s] configuration import failed', $this->appId),
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try
    }//end loadConfiguration()
}//end class
