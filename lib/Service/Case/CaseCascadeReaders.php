<?php

/**
 * OpenRegister Case Cascade Readers
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
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

namespace OCA\OpenRegister\Service\Case;

/**
 * What one cascade pass reads besides the rows: the entry and exit criteria, how realisations ended, and the anchor.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
 */
final readonly class CaseCascadeReaders {

	/**
	 * Bundle the collaborators.
	 *
	 * @param CaseSentryEvaluator $sentries Entry and exit criteria.
	 * @param CaseRealisationService $realiser Reads how realisations ended.
	 * @param CaseAnchorReader $anchor Reads the anchoring object.
	 */
	public function __construct(
		public CaseSentryEvaluator $sentries,
		public CaseRealisationService $realiser,
		public CaseAnchorReader $anchor,
	) {
	}//end __construct()
}//end class
