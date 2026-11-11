<?php

/**
 * OpenRegister QualityListingRequest
 *
 * A request for the lowest-quality listing of one register/schema: which register and schema, which status, and which window.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-surface-api/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

/**
 * The register, the schema, the optional status filter and the window of one lowest-quality listing.
 *
 * @spec openspec/changes/mdm-surface-api/tasks.md#task-1
 */
final class QualityListingRequest {

	/**
	 * Constructor.
	 *
	 * @param int|string           $register      Register id, uuid or slug.
	 * @param int|string           $schema        Schema id, uuid or slug.
	 * @param string|null          $qualityStatus Optional status filter (`good`/`fair`/`poor`).
	 * @param QualityListingWindow $window        Sort field and order, page size and offset.
	 */
	public function __construct(
		public readonly mixed $register,
		public readonly mixed $schema,
		public readonly ?string $qualityStatus,
		public readonly QualityListingWindow $window,
	) {
	}//end __construct()
}//end class
