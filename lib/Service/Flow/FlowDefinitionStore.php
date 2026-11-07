<?php

/**
 * OpenRegister Flow Definition Store
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowMapper;

/**
 * Where flow definitions live: the flow table and the trigger index derived from its nodes.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
 */
final readonly class FlowDefinitionStore {

	/**
	 * Bundle the collaborators.
	 *
	 * @param FlowMapper $mapper Reads and writes flow definitions.
	 * @param FlowTriggerIndex $triggerIndex Keeps the trigger index derived from the nodes.
	 */
	public function __construct(
		public FlowMapper $mapper,
		public FlowTriggerIndex $triggerIndex,
	) {
	}//end __construct()
}//end class
