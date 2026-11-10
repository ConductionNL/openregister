<?php

/**
 * What the three trigger flow nodes have in common.
 *
 * "When someone runs it", "When an object changes" and "On a schedule" differ
 * in their palette text and in what their config must name. Each is otherwise
 * the same thing: a way into a flow, available in both scopes, that does no
 * work of its own when the engine reaches it and so passes its items through.
 * That shared behaviour lives here once.
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

use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\IFlowSideEffectFree;
use OCA\OpenRegister\Service\Flow\IFlowTriggerNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;

/**
 * The shared half of a trigger node. Every trigger is a trigger, declares
 * its config vocabulary and taxonomy, and has no side effect when reached.
 *
 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md
 */
abstract class TriggerNodeBase implements IFlowTriggerNode, IFlowNodeConfigKeys, IFlowNodeTaxonomy, IFlowSideEffectFree {
	/**
	 * Constructor.
	 *
	 * @param IL10N         $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 */
	public function __construct(
		protected readonly IL10N $l10n,
		protected readonly IURLGenerator $urls,
	) {

	}//end __construct()

	/**
	 * Available in both scopes.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return bool Whether it is available.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * A trigger does nothing when reached: what started the run is already in
	 * the items, so they pass through unchanged.
	 *
	 * @param array $items   The input items.
	 * @param array $config  The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The same items.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md
	 */
	public function execute(array $items, array $config, array $context): array {
		return $items;
	}//end execute()

	/**
	 * What kind of step this is: a way into the flow.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_EVENT;
	}//end getKind()

	/**
	 * Where an author should look for this step.
	 *
	 * @return string The palette category.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-palette-category-independent-of-its-kind
	 */
	public function getCategory(): string {
		return IFlowNodeTaxonomy::CATEGORY_TRIGGERS;
	}//end getCategory()
}//end class
