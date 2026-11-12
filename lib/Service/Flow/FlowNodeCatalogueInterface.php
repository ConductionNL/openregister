<?php

/**
 * FlowNodeCatalogueInterface: the node registry as the graph checks ask it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-declares-whether-it-triggers-or-ends-a-path
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The registered flow-step types plus whether a type begins or ends a path.
 *
 * The connectivity check, the preflight and the object-action steps flow ask
 * both questions of the registry; everyone else only resolves a type, and
 * types against {@see FlowNodeRegistryInterface}.
 */
interface FlowNodeCatalogueInterface extends FlowNodeRegistryInterface {

	/**
	 * Whether a node TYPE ends a path.
	 *
	 * @param string $type The node type id.
	 *
	 * @return bool True when the type is registered and marks itself an end.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-declares-whether-it-triggers-or-ends-a-path
	 */
	public function isEnd(string $type): bool;

	/**
	 * Whether a run may BEGIN at a node TYPE.
	 *
	 * @param string $type The node type id.
	 *
	 * @return bool True when the type is registered and marks itself a trigger.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-declares-whether-it-triggers-or-ends-a-path
	 */
	public function isTrigger(string $type): bool;
}//end interface
