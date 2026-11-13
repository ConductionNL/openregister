<?php

/**
 * ActionRightsCatalogue: Open Register's action matrix as a described list.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\FlowNodeRightsGuardInterface;

/**
 * Lists the action rights of the matrix and the rights flow steps declare,
 * and writes the groups of the known ones.
 *
 * An action is a grant to a person (who may create a flow, who may put an
 * e-mail step in one), not a verb on an object, so it is listed apart from
 * the object permissions.
 */
class ActionRightsCatalogue implements ActionRightsCatalogueInterface {

	/**
	 * The app the rights belong to.
	 *
	 * @var string
	 */
	private const APP = 'openregister';

	/**
	 * Descriptions of the rights that are not node rights.
	 *
	 * @var array<string, string>
	 */
	private const DESCRIPTIONS = [
		'flow.create' => 'Create a flow, or import one from BPMN.',
		'flow.update' => 'Change, publish, draft, deprecate or adopt a flow.',
		'flow.delete' => 'Delete a flow.',
		'flow.run' => 'Run a flow by hand.',
		'flow.read' => 'Read flows, their versions and their BPMN export.',
		'object.correct' => 'Correct a recorded value of an object.',
	];

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterActionAuthService $matrix     The action matrix.
	 * @param FlowNodeRightsGuardInterface  $nodeRights The rights flow steps declare.
	 */
	public function __construct(
		private readonly OpenRegisterActionAuthService $matrix,
		private readonly FlowNodeRightsGuardInterface $nodeRights,
	) {
	}//end __construct()

	/**
	 * Every action right: its app, description and, for node rights, the node types that need it.
	 *
	 * @return array<int, array{action: string, app: string, description: string, nodeTypes: array<int, string>}>
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function actions(): array {
		$nodeRights = $this->nodeRights->nodeRights();
		$names      = array_unique(array_merge(array_keys($this->matrix->getMatrix()), array_keys($nodeRights)));
		sort($names);

		$actions = [];
		foreach ($names as $name) {
			$name      = (string) $name;
			$actions[] = [
				'action' => $name,
				'app' => self::APP,
				'description' => ($nodeRights[$name]['description'] ?? (self::DESCRIPTIONS[$name] ?? '')),
				'nodeTypes' => ($nodeRights[$name]['nodeTypes'] ?? []),
			];
		}

		return $actions;
	}//end actions()

	/**
	 * Every action right with the groups that hold it.
	 *
	 * A right with no matrix entry is listed with `["admin"]`, which is what it grants.
	 *
	 * @return array<int, array{action: string, app: string, description: string, nodeTypes: array<int, string>, groups: array<int, string>}>
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function actionsWithGroups(): array {
		$actions = $this->actions();
		foreach ($actions as $index => $action) {
			$actions[$index]['groups'] = $this->matrix->getAllowedGroups(action: $action['action']);
		}

		return $actions;
	}//end actionsWithGroups()

	/**
	 * Change the groups of known actions; the others keep theirs.
	 *
	 * @param array<string, array<int, string>> $groups Groups per action.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException Naming every unknown action, or a malformed group list.
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function setGroups(array $groups): void {
		$known   = array_column($this->actions(), 'action');
		$unknown = array_values(array_diff(array_map('strval', array_keys($groups)), $known));
		if ($unknown !== []) {
			throw new InvalidArgumentException('Unknown action right: '.implode(', ', $unknown).'.');
		}

		$matrix = $this->matrix->getMatrix();
		foreach ($groups as $action => $list) {
			if (is_array($list) === false || array_filter($list, static fn (mixed $group): bool => is_string($group) === false) !== []) {
				throw new InvalidArgumentException(sprintf('The groups of "%s" must be a list of group ids.', $action));
			}

			$matrix[(string) $action] = array_values($list);
		}

		$this->matrix->setMatrix($matrix);
	}//end setGroups()
}//end class
