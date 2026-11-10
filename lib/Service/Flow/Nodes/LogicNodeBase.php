<?php

/**
 * What the logic-category flow nodes have in common.
 *
 * Explode, filter, merge, router, switch, set-fields, map, action-inputs,
 * iterate, flow-state, end, wait, sub-flow and decision-table each do their
 * own work, but they are built from the same translator and URL generator,
 * are offered in both workflow scopes, and sit in the palette's "logic"
 * category. That part is kept here once.
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
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;

/**
 * The shared half of a logic-category node.
 *
 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-palette-category-independent-of-its-kind
 */
abstract class LogicNodeBase {
	/**
	 * Constructor.
	 *
	 * @param IL10N         $l10n Translations for palette text and refusals.
	 * @param IURLGenerator $urls For the palette icon.
	 */
	public function __construct(
		protected readonly IL10N $l10n,
		protected readonly IURLGenerator $urls,
	) {

	}//end __construct()

	/**
	 * Where an author should look for this step.
	 *
	 * @return string The palette category.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-palette-category-independent-of-its-kind
	 */
	public function getCategory(): string {
		return IFlowNodeTaxonomy::CATEGORY_LOGIC;
	}//end getCategory()

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
}//end class
