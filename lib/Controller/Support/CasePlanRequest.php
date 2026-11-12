<?php

/**
 * OpenRegister CasePlanRequest
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

use OCA\OpenRegister\Service\Case\CaseAnchor;
use OCA\OpenRegister\Service\Case\CasePlanProvenance;

/**
 * A request to create a case plan: the object it anchors on, the definition
 * and where that definition came from.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
 */
final class CasePlanRequest {
	/**
	 * Describe the request.
	 *
	 * @param CaseAnchor           $anchor     The anchoring object, register and schema
	 * @param array<string, mixed> $definition The definition (`settings`, `items`)
	 * @param CasePlanProvenance   $provenance The definition's flow and version, when any
	 */
	public function __construct(
		public readonly CaseAnchor $anchor,
		public readonly array $definition,
		public readonly CasePlanProvenance $provenance,
	) {
	}//end __construct()
}//end class
