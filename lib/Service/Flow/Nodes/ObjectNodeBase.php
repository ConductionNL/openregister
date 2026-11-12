<?php

/**
 * What the object-category flow nodes have in common.
 *
 * Transition, create-or-link, object-read, object-write and loop each work on
 * register objects in their own way, through their own collaborators. They
 * share only how the palette sees them: offered in both workflow scopes, a
 * BPMN service task, and filed under "objects". That part is kept here once.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCP\WorkflowEngine\IManager;

/**
 * The shared half of an object-category node.
 *
 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md
 */
abstract class ObjectNodeBase {
	/**
	 * What kind of step this is: it calls the register.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SERVICE_TASK;
	}//end getKind()

	/**
	 * Available in both workflow scopes.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return bool Whether the node is available.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * Where an author should look for this step.
	 *
	 * @return string The palette category.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-palette-category-independent-of-its-kind
	 */
	public function getCategory(): string {
		return IFlowNodeTaxonomy::CATEGORY_OBJECTS;
	}//end getCategory()
}//end class
