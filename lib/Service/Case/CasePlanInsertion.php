<?php

/**
 * OpenRegister Case Plan Insertion
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
 * What every row of one creation shares: the anchor, the plan settings, the creating identity and the provenance.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
 */
final readonly class CasePlanInsertion {

	/**
	 * The shared part of a plan creation.
	 *
	 * @param CaseAnchor $anchor The anchoring object.
	 * @param array<string, mixed> $settings The plan settings, carried on every row.
	 * @param string|null $actor The creating identity.
	 * @param CasePlanProvenance $provenance The definition's flow and version, when any, and the origin every row gets.
	 */
	public function __construct(
		public CaseAnchor $anchor,
		public array $settings,
		public ?string $actor,
		public CasePlanProvenance $provenance = new CasePlanProvenance(),
	) {
	}//end __construct()
}//end class
