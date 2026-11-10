<?php

/**
 * OpenRegister CasePlanCreation
 *
 * A request to create a case plan on an object: where it anchors, the definition, who asks and where the definition came from.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
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
 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * The anchor, the definition, the acting identity and the provenance of one plan creation.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
 */
final class CasePlanCreation {

	/**
	 * Constructor.
	 *
	 * @param CaseAnchor           $anchor     The anchoring object, its register and schema.
	 * @param array<string, mixed> $definition The definition (`settings`, `items`).
	 * @param string|null          $actor      The creating identity (a user or `system:<app>`).
	 * @param CasePlanProvenance   $provenance Where the definition came from, when known.
	 */
	public function __construct(
		public readonly CaseAnchor $anchor,
		public readonly array $definition,
		public readonly ?string $actor,
		public readonly CasePlanProvenance $provenance = new CasePlanProvenance(),
	) {
	}//end __construct()
}//end class
