<?php

/**
 * PerformanceOptimizationHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use Psr\Log\LoggerInterface;

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
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class PerformanceOptimizationHandler {

	/**
	 * Constructor for PerformanceOptimizationHandler.
	 *
	 * @param OrganisationServiceInterface $organisationService Organisation service for context.
	 * @param LoggerInterface $logger Logger for debugging.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly OrganisationServiceInterface $organisationService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the active organization for the current user context.
	 *
	 * This method determines the active organization using the OrganisationService
	 * to ensure consistency between save and retrieval operations.
	 *
	 * @return string|null The active organisation UUID or null if not available.
	 *
	 * @psalm-return   string|null
	 * @phpstan-return string|null
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getActiveOrganisationForContext(): ?string {
		try {
			return $this->activeOrganisationUuid();
		} catch (Exception $e) {
			// Log error but continue without organization context.
			$this->logger->warning(
				message: '[PerformanceOptimizationHandler] Failed to get active organisation',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end getActiveOrganisationForContext()

	/**
	 * The active organisation's uuid, logged; null when there is none.
	 *
	 * @return string|null
	 */
	private function activeOrganisationUuid(): ?string {
		$organisation = $this->organisationService->getActiveOrganisation();
		if ($organisation === null) {
			$this->logger->debug(
				message: '[PerformanceOptimizationHandler] No active organisation for current user',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		$this->logger->debug(
			message: '[PerformanceOptimizationHandler] Got active organisation for context',
			context: ['file' => __FILE__, 'line' => __LINE__, 'organisationUuid' => $organisation->getUuid(), 'organisationName' => $organisation->getName()]
		);
		return $organisation->getUuid();
	}//end activeOrganisationUuid()

}//end class
