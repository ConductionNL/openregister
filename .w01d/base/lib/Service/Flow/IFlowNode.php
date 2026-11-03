<?php

/**
 * A node type contributed to the flow palette by an app.
 *
 * Deliberately shaped after `OCP\WorkflowEngine\IOperation`: the metadata half
 * (display name, description, icon, scope) is the same set of questions, asked
 * the same way, so an author moving between Nextcloud Flow and an OpenRegister
 * flow meets one vocabulary. What is added is the half `IOperation` cannot
 * provide — see below.
 *
 * WHY NOT `IOperation` DIRECTLY
 * ----------------------------
 * Nextcloud Flow was evaluated as the node contract and cannot serve as one.
 * Core invokes an operation like this
 * (`apps/workflowengine/lib/AppInfo/Application.php`):
 *
 *     $entity->prepareRuleMatcher($ruleMatcher, $eventName, $event);
 *     $operation->onEvent($eventName, $event, $ruleMatcher);
 *
 * Four properties of that call make it unusable as a graph step:
 *
 *  1. `onEvent()` returns **void**. A flow node must return items; there is
 *     nowhere for its output to go.
 *  2. It receives an `Event` and an `IRuleMatcher`, never data. It is a
 *     listener signature, not a callable one.
 *  3. Control is inverted: the operation asks the rule matcher which rules
 *     matched and acts on its own. In a graph the ENGINE decides what runs.
 *  4. An operation is bound to an entity and its events
 *     (`ISpecificOperation::getEntityId()`), i.e. "when X happens, do Y" — a
 *     one-step rule, not a step that can sit anywhere in a graph.
 *
 * So the contract is ours, but everything that CAN be borrowed is: the
 * metadata methods below mirror `IOperation`, the scope constants are
 * Nextcloud's own, and discovery uses Nextcloud's registration pattern
 * ({@see RegisterFlowNodesEvent}) rather than a bespoke one.
 *
 * The two systems then compose rather than compete: a Nextcloud Flow rule can
 * start an OpenRegister flow, and an OpenRegister flow can do what Nextcloud
 * Flow cannot — branch, join, loop, and carry data between steps.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Contract for a contributed flow node type.
 */
interface IFlowNode {
	/**
	 * The step `type` this node answers to, unique across the fleet.
	 *
	 * Namespace it with the owning app (`hermiq.agent-step`) so two apps
	 * cannot silently claim one type. Collisions are refused at registration,
	 * not resolved by load order.
	 *
	 * @return string The type identifier.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getId(): string;

	/**
	 * Human-readable name for the palette. Mirrors `IOperation`.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getDisplayName(): string;

	/**
	 * What this node does, in one sentence. Mirrors `IOperation`.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getDescription(): string;

	/**
	 * Absolute URL of the palette icon. Mirrors `IOperation`.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getIcon(): string;

	/**
	 * Whether this node is offered in the given scope.
	 *
	 * Takes Nextcloud's own `IManager::SCOPE_ADMIN` / `SCOPE_USER`, so a node
	 * that is admin-only in a Nextcloud Flow rule is admin-only here too.
	 * Mirrors `IOperation`.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return bool Whether it is available.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function isAvailableForScope(int $scope): bool;

	/**
	 * Reject a configuration the author cannot have meant.
	 *
	 * Called when a flow is saved, not when it runs, so a broken step is
	 * caught in the editor instead of at 3am in a scheduled run. Mirrors
	 * `IOperation::validateOperation()`, which exists for the same reason.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @throws \UnexpectedValueException When the configuration is unusable.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function validateConfig(array $config): void;

	/**
	 * Do the work: items in, items out.
	 *
	 * This is the method `IOperation` has no equivalent of, and the reason
	 * this interface exists at all.
	 *
	 * A node that acts per record acts once per item and returns one item per
	 * result. A node that filters returns fewer items than it received. An
	 * empty returned list is meaningful — it ends that branch's data.
	 *
	 * Throwing is meaningful: the engine reads the step's `onError` policy to
	 * decide whether to stop, continue, or dead-letter. Catching here defeats
	 * that policy and produces a run that reports success while doing nothing.
	 *
	 * @param array $items The input items ({@see FlowItems}).
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata — NOT the data channel.
	 *
	 * @return array The output items.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function execute(array $items, array $config, array $context): array;
}//end interface
