<?php

/**
 * OpenRegister Repair — import the MDM trust-configuration register.
 *
 * OpenRegister does not self-import its own `lib/Settings` register
 * descriptors (ADR-037; the `ImportCredentialBrokerRegister` /
 * `ImportDsarRegister` / `ImportEdepotTransferRegister` precedent). This step
 * imports/upgrades the `trust-configuration` register (the `trustConfiguration`
 * schema) that the survivorship engine reads: `TrustTierResolver` resolves a
 * `(entityType, attribute, sourceSystem)` tuple to the tier that decides which
 * upstream source wins for a golden-record attribute.
 *
 * Without this step the register never exists, so every consumer degrades:
 * `SurvivorshipRecomputeListener::loadTrustRows()` swallows the lookup failure
 * and returns `[]` — silently falling back to each annotation's `defaultTier` —
 * while a leaf app seeding its own rows (pipelinq's `SeedTrustConfigurationRows`)
 * fails loudly with "Did expect one result but found none". `trust_configuration_register.json`
 * was the only descriptor in `lib/Settings/` shipped without an importer.
 * Version-gated (`force: false`); re-runs are no-ops.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
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
 * Import the MDM trust-configuration register from lib/Settings.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 */
class ImportTrustConfigurationRegister implements IRepairStep
{

    /**
     * App-relative descriptor path.
     *
     * @var string
     */
    private const REGISTER_PATH = '/lib/Settings/trust_configuration_register.json';

    /**
     * Descriptor version passed to the importer's version_compare gate.
     *
     * @var string
     */
    private const REGISTER_VERSION = '1.0.0';

    /**
     * Configuration identity for the descriptor (its own appId so the
     * importFromApp version gate is independent of the other system registers).
     *
     * @var string
     */
    private const CONFIG_APP_ID = 'openregister.trust-configuration';

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
     */
    public function getName(): string
    {
        return 'Import OpenRegister MDM trust-configuration register (source trust tiers for survivorship)';
    }//end getName()

    /**
     * Run the repair step, importing the trust-configuration register descriptor.
     *
     * @param IOutput $output Output interface for status messages.
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        try {
            $path = $this->appManager->getAppPath('openregister').self::REGISTER_PATH;
            if (is_file($path) === false) {
                $output->warning('Trust-configuration register descriptor not found: '.$path);
                return;
            }

            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data) === false) {
                $output->warning('Trust-configuration register descriptor is not valid JSON: '.$path);
                return;
            }

            $this->configurationService->importFromApp(
                appId: self::CONFIG_APP_ID,
                data: $data,
                version: self::REGISTER_VERSION,
                force: false
            );

            $output->info('MDM trust-configuration register imported (trustConfiguration)');
        } catch (Throwable $e) {
            $this->logger->warning('[ImportTrustConfigurationRegister] import failed: '.$e->getMessage());
            $output->warning('Trust-configuration register import skipped: '.$e->getMessage());
        }//end try
    }//end run()
}//end class
