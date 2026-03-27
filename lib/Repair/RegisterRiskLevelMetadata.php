<?php

/**
 * Repair step to register the risk level metadata key.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\RiskLevelService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Registers the openregister-risk-level metadata key with Nextcloud's
 * IFilesMetadataManager so it can be used to store risk levels on files.
 *
 * @category  Repair
 * @package   OCA\OpenRegister\Repair
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class RegisterRiskLevelMetadata implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param RiskLevelService $riskLevelService Risk level service
     */
    public function __construct(
        private readonly RiskLevelService $riskLevelService
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Register OpenRegister risk level file metadata';
    }//end getName()

    /**
     * Run the repair step.
     *
     * @param IOutput $output Output interface for status messages
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        $this->riskLevelService->initMetadataKey();
        $output->info('Registered openregister-risk-level metadata key');
    }//end run()
}//end class
