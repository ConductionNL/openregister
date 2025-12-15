<?php

/**
 * PerformanceOptimizationHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\ObjectService;

use OCA\OpenRegister\Service\OrganisationService;
use Exception;

/**
 * Handles performance optimization utilities for ObjectService.
 *
 * This handler provides:
 * - Active organization context retrieval
 * - Request optimization for performance
 * - Performance monitoring utilities
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class PerformanceOptimizationHandler
{


    /**
     * Constructor for PerformanceOptimizationHandler.
     *
     * @param OrganisationService $organisationService Service for organization operations.
     */
    public function __construct(
    private readonly OrganisationService $organisationService
    ) {
    }//end __construct()


    /**
     * Get the active organization for the current user context.
     *
     * This method determines the active organization using the same logic as SaveObject
     * to ensure consistency between save and retrieval operations.
     *
     * @return string|null The active organization UUID or null if none found.
     *
     * @psalm-return   string|null
     * @phpstan-return string|null
     */
    public function getActiveOrganisationForContext(): ?string
    {
        try {
            $activeOrganisation = $this->organisationService->getActiveOrganisation();

            if ($activeOrganisation !== null) {
                return $activeOrganisation->getUuid();
            } else {
                return null;
            }
        } catch (Exception $e) {
            // Log error but continue without organization context.
            return null;
        }

        return null;
    }//end getActiveOrganisationForContext()
}//end class
