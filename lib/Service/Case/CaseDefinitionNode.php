<?php

/**
 * One node of a case-plan definition, with its key and type established.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Exception\CaseValidationException;

/**
 * A definition node whose key has been accepted and whose type is one of the
 * five; it answers what its type forbids, the flow it binds and its children.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
 */
final class CaseDefinitionNode {

	/**
	 * The node as submitted, its `key` already trimmed.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $node;

	/**
	 * Its key.
	 *
	 * @var string
	 */
	public readonly string $key;

	/**
	 * Its plan-item type.
	 *
	 * @var string
	 */
	public readonly string $type;

	/**
	 * Accept a node whose key is established; refuse an unknown type.
	 *
	 * @param array<string, mixed> $node The node, its `key` already trimmed.
	 * @param string               $path Where it is, for messages.
	 *
	 * @throws CaseValidationException On an unknown type.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
	 */
	public function __construct(array $node, string $path) {
		$this->node = $node;
		$this->key = (string)$node['key'];
		$this->type = (string)($node['type'] ?? '');
		if (in_array($this->type, CaseItem::TYPES, true) === false) {
			throw new CaseValidationException(
				message: sprintf("%s ('%s') has type '%s'; expected one of %s.", $path, $this->key, $this->type, implode(', ', CaseItem::TYPES))
			);
		}
	}//end __construct()

	/**
	 * What the node's type forbids: a discretionary milestone, and children or
	 * a flow binding on anything that is not a stage.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException On a forbidden combination.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
	 */
	public function validateTypeRules(): void {
		if ($this->type === CaseItem::TYPE_MILESTONE && ($this->node['discretionary'] ?? false) === true) {
			throw new CaseValidationException(message: sprintf("'%s' is a milestone and cannot be discretionary: a milestone is never enabled.", $this->key));
		}

		if ($this->type !== CaseItem::TYPE_STAGE) {
			$this->validateLeaf();
		}
	}//end validateTypeRules()

	/**
	 * A node that is not a stage contains no children, and binds a flow only as a process task.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException On children, or on a flow it may not bind.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
	 */
	private function validateLeaf(): void {
		if (isset($this->node['children']) === true) {
			throw new CaseValidationException(message: sprintf("'%s' is a %s and cannot contain children; only a stage nests.", $this->key, $this->type));
		}

		if (isset($this->node['flow']) === true && $this->type !== CaseItem::TYPE_PROCESS_TASK) {
			throw new CaseValidationException(
				message: sprintf("'%s' is a %s and cannot bind a flow; only a stage or a process task is realised by a run.", $this->key, $this->type)
			);
		}
	}//end validateLeaf()

	/**
	 * The flow the node binds, trimmed; a process task must name one.
	 *
	 * @return string The flow, or '' when the node binds none.
	 *
	 * @throws CaseValidationException For a process task without a flow.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	public function boundFlow(): string {
		$flow = trim((string)($this->node['flow'] ?? ''));
		if ($this->type === CaseItem::TYPE_PROCESS_TASK && $flow === '') {
			throw new CaseValidationException(
				message: sprintf("'%s' is a process task and names no flow; a process task without a flow would be work nobody does.", $this->key)
			);
		}

		return $flow;
	}//end boundFlow()

	/**
	 * The children of a stage as submitted, refused unless they are a list
	 * and the stage binds no flow; every other node has none.
	 *
	 * @param string $flow The flow it binds, or ''.
	 *
	 * @return array<int|string, mixed> The children to validate.
	 *
	 * @throws CaseValidationException When a stage's children are malformed or compete with its flow.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function stageChildren(string $flow): array {
		if ($this->type !== CaseItem::TYPE_STAGE) {
			return [];
		}

		$children = ($this->node['children'] ?? []);
		if (is_array($children) === false) {
			throw new CaseValidationException(message: sprintf("'%s'.children must be a list.", $this->key));
		}

		// A stage bound to a flow is driven by its run; children under it
		// would have two masters.
		if ($flow !== '' && $children !== []) {
			throw new CaseValidationException(message: sprintf("'%s' binds a flow and cannot also contain children.", $this->key));
		}

		return $children;
	}//end stageChildren()
}//end class
