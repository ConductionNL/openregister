<?php
/**
 * NodeRightsMapInterface: the administrator's map of flow node type to right, as the action-rights API asks it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The node map the action-rights API reads and writes beside the action rights.
 *
 * The action-rights API (Core) publishes and changes the map; Processes runs the
 * flows that the map guards, keeps it, and binds its answer in its own wiring.
 *
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
 */
interface NodeRightsMapInterface {

	/**
	 * Every node right starts with this prefix.
	 *
	 * @var string
	 */
	public const RIGHT_PREFIX = 'flow.node.';

	/**
	 * The administrator's map of node type to right (null lifts a node's own right).
	 *
	 * @return array<string, string|null>
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function adminMap(): array;

	/**
	 * Store the administrator's map of node type to right.
	 *
	 * The caller MUST have checked that the user is an administrator.
	 *
	 * @param array<string, string|null> $map The map.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function setAdminMap(array $map): void;
}//end interface
