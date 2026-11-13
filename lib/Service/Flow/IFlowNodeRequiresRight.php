<?php

/**
 * Optional companion to IFlowNode: the right an author needs to use a step.
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
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * A step type that an author may only put in a flow when they hold a named right.
 *
 * Optional, like {@see IFlowNodeTaxonomy}: adding a method to IFlowNode would be
 * a fatal error for every node another app ships. A node that does not
 * implement this needs no right beyond the flow rights.
 *
 * A right is an action in Open Register's action matrix and starts with
 * `flow.node.`, so it cannot shadow `flow.create` or an app's other actions.
 */
interface IFlowNodeRequiresRight {

	/**
	 * The prefix every node right starts with.
	 *
	 * @var string
	 */
	public const RIGHT_PREFIX = 'flow.node.';

	/**
	 * The right a step with this configuration needs, or null when it needs none.
	 *
	 * Takes the configuration because one node can be ordinary in one use and
	 * powerful in another (an object write needs a right only to delete).
	 *
	 * @param array<string, mixed> $config The step's configuration.
	 *
	 * @return string|null The right, or null.
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function requiredRight(array $config): ?string;

	/**
	 * Every right this node can require, with a sentence an administrator reads.
	 *
	 * Used to add the rights to the action matrix and to describe them in the
	 * permission catalogue, without guessing at configurations.
	 *
	 * @return array<string, string> Descriptions keyed by right.
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function declaredRights(): array;
}//end interface
