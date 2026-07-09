<?php

/**
 * OpenRegister Repair — import/upgrade the shipped DSAR registers.
 *
 * OpenRegister does NOT self-import its own `lib/Settings` register
 * descriptors (ADR-037: register config reaches an instance through an
 * explicit Repair step — the `ImportCredentialBrokerRegister` precedent).
 * Without this step the DSAR registers' declared behaviour — the
 * `escalationTier` calculation, the deadline reminder/escalation/breach
 * notification rules, the `breachedAt` stamp, the `dpiaFlagged` rule, and
 * the policy pack's `dpiaDetection` + `privacyOfficerGroup` fields
 * (dsar-escalation-and-dpia) — never reaches a live instance, leaving the
 * temporal sweep and the DPIA detection job with nothing to make live.
 *
 * The import is version-gated (`ConfigurationService::importFromApp` with
 * `force: false`), so existing installs upgrade their schema definitions in
 * place and re-runs are no-ops.
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
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
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
 * Import the DSAR case + policy-pack registers from lib/Settings.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
 */
class ImportDsarRegisters implements IRepairStep
{

    /**
     * App-relative descriptor paths mapped to their configuration identity
     * (appId) + the descriptor version passed to the importer's
     * version_compare gate (bump alongside the schema versions).
     *
     * Each descriptor gets its OWN configuration appId: `importFromApp`
     * version-gates per configuration row, so two descriptors sharing one
     * appId silently skip whichever imports second (verified live — the
     * policy-pack file was version-gated away against the case register's
     * higher version under a shared 'openregister' id).
     *
     * @var array<string, array{appId: string, version: string}>
     */
    private const REGISTERS = [
        '/lib/Settings/data_subject_request_register.json' => [
            'appId'   => 'openregister.dsar-cases',
            'version' => '1.2.0',
        ],
        '/lib/Settings/dsar_policy_pack_register.json'     => [
            'appId'   => 'openregister.dsar-policy-packs',
            'version' => '1.1.0',
        ],
    ];

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
     * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
     *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
     */
    public function getName(): string
    {
        return 'Import OpenRegister DSAR registers (data-subject requests + policy packs)';
    }//end getName()

    /**
     * Run the repair step, importing both DSAR register descriptors.
     *
     * @param IOutput $output Output interface for status messages.
     *
     * @return void
     *
     * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
     *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
     */
    public function run(IOutput $output): void
    {
        foreach (self::REGISTERS as $relativePath => $descriptor) {
            $version = $descriptor['version'];

            try {
                $path = $this->appManager->getAppPath('openregister').$relativePath;
                if (is_file($path) === false) {
                    $output->warning('DSAR register descriptor not found: '.$path);
                    continue;
                }

                // Import the DECODED descriptor via importFromApp() — NOT
                // importFromFilePath(), which rejects an absolute path (it
                // expects a Nextcloud-root-relative one) and would fail
                // closed here. importFromApp() takes the data directly.
                $data = json_decode((string) file_get_contents($path), true);
                if (is_array($data) === false) {
                    $output->warning('DSAR register descriptor is not valid JSON: '.$path);
                    continue;
                }

                $this->configurationService->importFromApp(
                    appId: $descriptor['appId'],
                    data: $data,
                    version: $version,
                    force: false
                );

                $output->info('DSAR register imported: '.basename($relativePath).' (v'.$version.')');
            } catch (Throwable $e) {
                $this->logger->warning('[ImportDsarRegisters] import failed for '.$relativePath.': '.$e->getMessage());
                $output->warning('DSAR register import skipped for '.basename($relativePath).': '.$e->getMessage());
            }//end try
        }//end foreach
    }//end run()
}//end class
