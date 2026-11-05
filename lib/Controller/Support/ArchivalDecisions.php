<?php

/**
 * OpenRegister archival domain
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Archival\DestructionReviewService;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\Archival\LegalHoldService;
use OCA\OpenRegister\Service\Archival\ReviewOutcomeService;

/**
 * The domain services of the archival API.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class ArchivalDecisions {
	/**
	 * Bundle the services.
	 *
	 * @param DestructionService       $destructionService Destruction service.
	 * @param LegalHoldService         $legalHoldService   Legal hold service.
	 * @param DestructionReviewService $reviews            Assignment, sign-off and the decision history.
	 * @param ReviewOutcomeService     $outcomes           Carries an answer out against the record.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function __construct(
		public readonly DestructionService $destructionService,
		public readonly LegalHoldService $legalHoldService,
		public readonly DestructionReviewService $reviews,
		public readonly ReviewOutcomeService $outcomes,
	) {
	}//end __construct()
}//end class
