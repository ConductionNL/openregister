<?php

/**
 * ImportFlowRegister — materialises the OpenRegister-native flow store.
 *
 * OpenRegister owns the flow engine but shipped no store to author a flow in:
 * only a consuming app could hold one. This repair step imports
 * `lib/Settings/flow_register.json` via `ConfigurationService::importFromApp()`
 * so the `flows` register and its `flow` schema land idempotently on install and
 * `occ upgrade` (matched by slug, version-gated) — the same register-import-via-
 * Repair convention as {@see ImportCredentialBrokerRegister}. With the store in
 * place the `OpenRegisterFlowResolver` (which reads this register/schema by
 * default) resolves flows authored here, so triggers, sub-flows and the /test
 * endpoint all work with an OpenRegister-native flow. Never throws — a failure
 * logs a warning and leaves the instance otherwise healthy.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/or-flow-store/specs/flow-store/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\ConfigurationService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imports the flow register descriptor idempotently on upgrade/install.
 */
class ImportFlowRegister implements IRepairStep
{
    /**
     * App-relative path to the register descriptor imported by this step.
     *
     * @var string
     */
    private const REGISTER_PATH = '/lib/Settings/flow_register.json';

    /**
     * Descriptor version passed to the importer's version_compare gate.
     *
     * @var string
     */
    private const REGISTER_VERSION = '1.4.0';

    /**
     * Constructor.
     *
     * @param ConfigurationService $configurationService The OR configuration importer.
     * @param IAppManager          $appManager           Resolves the openregister app path on disk.
     * @param LoggerInterface      $logger               Logger for import diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string The step name.
     *
     * @spec openspec/changes/or-flow-store/specs/flow-store/spec.md
     */
    public function getName(): string
    {
        return 'Import OpenRegister flow register (flows register + flow schema)';
    }//end getName()

    /**
     * Run the repair step, importing the flow register descriptor.
     *
     * @param IOutput $output Output interface for status messages.
     *
     * @return void
     *
     * @spec openspec/changes/or-flow-store/specs/flow-store/spec.md
     */
    public function run(IOutput $output): void
    {
        try {
            $path = $this->appManager->getAppPath('openregister').self::REGISTER_PATH;
            if (is_file($path) === false) {
                $output->warning('Flow register descriptor not found: '.$path);
                return;
            }

            // Import the DECODED descriptor via importFromApp() — not
            // importFromFilePath(), which expects a Nextcloud-root-relative path
            // and would fail closed on this absolute one.
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data) === false) {
                $output->warning('Flow register descriptor is not valid JSON: '.$path);
                return;
            }

            $this->configurationService->importFromApp(
                appId: 'openregister',
                data: $data,
                version: self::REGISTER_VERSION,
                force: false
            );

            $output->info('Flow register imported (flows register + flow schema)');
        } catch (Throwable $e) {
            $this->logger->warning('[ImportFlowRegister] import failed: '.$e->getMessage());
            $output->warning('Flow register import skipped: '.$e->getMessage());
        }//end try
    }//end run()
}//end class
