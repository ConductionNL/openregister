<?php

/**
 * OpenRegister Flow Version Graphs
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * What a flow version transition decides from a version's graph: the store
 * that pins it by hash, the lifecycle preconditions it must meet, the trigger
 * rows derived from it, and the semantic version it is given.
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */
final class FlowVersionGraphs {
	/**
	 * Bundle the graph collaborators.
	 *
	 * @param FlowDefinitionPin   $pin      Canonicalises and stores a graph by hash.
	 * @param FlowLifecycleGuard  $guard    The preconditions on every transition.
	 * @param FlowTriggerIndex    $triggers The derived trigger rows.
	 * @param FlowSemanticVersion $semver   What to call the version that results.
	 * @param FlowTriggerFieldsCheck|null $fields The publish check of watched trigger fields.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function __construct(
		public readonly FlowDefinitionPin $pin,
		public readonly FlowLifecycleGuard $guard,
		public readonly FlowTriggerIndex $triggers,
		public readonly FlowSemanticVersion $semver,
		public readonly ?FlowTriggerFieldsCheck $fields=null,
	) {
	}//end __construct()
}//end class
