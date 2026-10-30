<?php

/**
 * Puts the inputs an action was run with on every item, under `inputs`.
 *
 * An action's inputs travel in the run context (`context.inputs`), which a
 * template cannot read: `{{…}}` renders against the item. This step copies
 * them onto the item so every later step reads `{{inputs.from}}` with the
 * interpolation it already uses. `writeOnly` inputs are exchanged here for
 * the request-scoped values {@see ObjectActionSecrets} holds, so they are
 * usable by the steps and never stored with the run.
 *
 * Lowered inline `steps` start with this node. A stored flow bound to an
 * action may place it after its manual trigger for the same effect.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionSecrets;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;

/**
 * Copies the run's action inputs onto each item.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) FlowItems::item() and ObjectActionSecrets::read() are the
 *   stateless item builder and the request-scoped holder every reader shares.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */
class ActionInputsNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeTaxonomy {

	/**
	 * The step type.
	 *
	 * @var string
	 */
	public const TYPE = 'openregister.action-inputs';

	/**
	 * Constructor.
	 *
	 * @param IL10N         $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function getId(): string {
		return self::TYPE;
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Action inputs');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function getDescription(): string {
		return $this->l10n->t('Make the inputs the action was run with readable as {{inputs.name}}.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/rename.svg');
	}//end getIcon()

	/**
	 * Available in both scopes: it only copies what the run already carries.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return bool Whether it is available.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The step takes no configuration.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function configKeys(): array {
		return [];
	}//end configKeys()

	/**
	 * Nothing to validate.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function validateConfig(array $config): void {
	}//end validateConfig()

	/**
	 * Set `inputs` on every item from the run context and the held writeOnly values.
	 *
	 * @param array $items   The input items.
	 * @param array $config  The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The items, each carrying `inputs`.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function execute(array $items, array $config, array $context): array {
		$inputs = array_merge(
			(array)($context['inputs'] ?? []),
			ObjectActionSecrets::read(ref: ($context[ObjectActionSecrets::CONTEXT_KEY] ?? null))
		);

		$out = [];
		foreach ($items as $index => $item) {
			$json = (array)($item[FlowItems::JSON] ?? []);
			$json['inputs'] = $inputs;
			$out[] = FlowItems::item(json: $json, binary: (array)($item[FlowItems::BINARY] ?? []), fromItemIndex: $index);
		}

		return $out;
	}//end execute()

	/**
	 * The BPMN kind.
	 *
	 * @return string The kind.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SCRIPT_TASK;
	}//end getKind()

	/**
	 * The palette category.
	 *
	 * @return string The category.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function getCategory(): string {
		return IFlowNodeTaxonomy::CATEGORY_LOGIC;
	}//end getCategory()
}//end class
