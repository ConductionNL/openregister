<?php

/**
 * OpenRegister AppHost — Generic Initialize-Settings Repair Step
 *
 * Engine-owned generalisation of the per-app `InitializeSettings` repair step.
 * On install / post-migration it imports the leaf app's register JSON
 * (including `register.d/` fragments) through OpenRegister's
 * ConfigurationService — via {@see AppHostSettingsService::loadConfiguration()}.
 *
 * This is a REPAIR STEP, never a migration, preserving the fleet install-order
 * constraint (OpenRegister must be enabled before a leaf seeds its register).
 *
 * NC instantiates repair steps by the class name listed in the leaf app's
 * info.xml `<repair-steps>`, which must live in the leaf namespace. The leaf
 * therefore keeps a one-line subclass — `class InitializeSettings extends
 * GenericInitializeSettings {}` — and Bootstrap registers a factory binding it
 * to the app-scoped {@see AppHostSettingsService}. The subclass carries no
 * logic; this base owns all behaviour.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\AppHost\Repair
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

namespace OCA\OpenRegister\AppHost\Repair;

use OCA\OpenRegister\AppHost\Service\AppHostSettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generic install/upgrade repair step that imports a leaf app's register JSON.
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.3
 */
class GenericInitializeSettings implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param string                 $appId           The leaf app id (display only).
     * @param AppHostSettingsService $settingsService App-scoped settings service.
     * @param LoggerInterface        $logger          PSR logger.
     */
    public function __construct(
        protected readonly string $appId,
        protected readonly AppHostSettingsService $settingsService,
        protected readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Repair-step name.
     *
     * @return string
     */
    public function getName(): string
    {
        return sprintf('Initialize %s register and schemas via ConfigurationService', $this->appId);
    }//end getName()

    /**
     * Import the app's register configuration. Degrades (does not throw) when
     * OpenRegister is unavailable, preserving the fleet's disabled-OR posture.
     *
     * @param IOutput $output Repair output channel.
     *
     * @return void
     *
     * @spec openspec/changes/apphost-boilerplate-controllers/specs/apphost-boilerplate/spec.md — Requirement: Install Plumbing via Repair Steps
     */
    public function run(IOutput $output): void
    {
        $output->info(sprintf('Initializing %s configuration...', $this->appId));

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning('OpenRegister is not installed or enabled. Skipping auto-configuration.');
            $this->logger->warning(sprintf('[AppHost:%s] OpenRegister not available, skipping register initialization', $this->appId));
            return;
        }

        try {
            $result = $this->settingsService->loadConfiguration(force: false);

            if (($result['success'] ?? false) === true) {
                $version = ($result['version'] ?? 'unknown');
                $output->info(sprintf('%s configuration imported successfully (version: %s)', $this->appId, $version));
                return;
            }

            $message = ($result['message'] ?? 'unknown error');
            $output->warning(sprintf('%s configuration import issue: %s', $this->appId, $message));
        } catch (Throwable $e) {
            $output->warning(sprintf('Could not auto-configure %s: %s', $this->appId, $e->getMessage()));
            $this->logger->error(sprintf('[AppHost:%s] initialization failed', $this->appId), ['exception' => $e->getMessage()]);
        }//end try
    }//end run()
}//end class
