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

use OCA\OpenRegister\Service\Archival\DestructionReviewServiceInterface;
use OCA\OpenRegister\Service\Archival\DestructionServiceInterface;
use OCA\OpenRegister\Service\Archival\LegalHoldServiceInterface;
use OCA\OpenRegister\Service\Archival\ReviewOutcomeServiceInterface;

/**
 * The domain services of the archival API.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class ArchivalDecisions {
	/**
	 * Bundle the services.
	 *
	 * @param DestructionServiceInterface       $destructionService Destruction service.
	 * @param LegalHoldServiceInterface         $legalHoldService   Legal hold service.
	 * @param DestructionReviewServiceInterface $reviews            Assignment, sign-off and the decision history.
	 * @param ReviewOutcomeServiceInterface     $outcomes           Carries an answer out against the record.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function __construct(
		public readonly DestructionServiceInterface $destructionService,
		public readonly LegalHoldServiceInterface $legalHoldService,
		public readonly DestructionReviewServiceInterface $reviews,
		public readonly ReviewOutcomeServiceInterface $outcomes,
	) {
	}//end __construct()
}//end class
