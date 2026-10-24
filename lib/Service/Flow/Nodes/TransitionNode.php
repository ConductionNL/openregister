<?php

/**
 * Move an object through its lifecycle from inside a flow.
 *
 * `{action, data?, object?}`: runs `TransitionEngine::transition()` on the
 * item's object (or on the object `object` names) as the run's identity, so
 * the transition's guards, inputs, condition, authorization and audit behave
 * exactly as for a direct move. A refused move fails the step with the
 * engine's message; it never passes the item on as if the move happened.
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
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Applies a named lifecycle transition to each item's object.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) FlowValueTemplate::render() and FlowItems::item() are the
 *   stateless renderer and item builder every node uses.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
 */
class TransitionNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeTaxonomy {

	/**
	 * The step type.
	 *
	 * @var string
	 */
	public const TYPE = 'openregister.transition';

	/**
	 * Constructor.
	 *
	 * @param TransitionEngine $engine The lifecycle engine.
	 * @param IL10N            $l10n   Translations.
	 * @param IURLGenerator    $urls   For the palette icon.
	 */
	public function __construct(
		private readonly TransitionEngine $engine,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function getId(): string {
		return self::TYPE;
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Lifecycle transition');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function getDescription(): string {
		return $this->l10n->t('Move the object to its next status through a declared lifecycle transition.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/confirm.svg');
	}//end getIcon()

	/**
	 * Available in both scopes: the transition's own authorization still applies.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return bool Whether it is available.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The config vocabulary.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function configKeys(): array {
		return ['action', 'data', 'object'];
	}//end configKeys()

	/**
	 * Refuse a step that names no transition.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When `action` is missing or `data` is not an object.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function validateConfig(array $config): void {
		if (is_string($config['action'] ?? null) === false || trim((string)$config['action']) === '') {
			throw new UnexpectedValueException($this->l10n->t('Name the lifecycle transition to take in "action".'));
		}

		if (array_key_exists('data', $config) === true && is_array($config['data']) === false) {
			throw new UnexpectedValueException($this->l10n->t('"data" must be an object of transition inputs.'));
		}
	}//end validateConfig()

	/**
	 * Take the transition on each item's object.
	 *
	 * @param array $items   The input items.
	 * @param array $config  The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array One item per moved object, carrying the saved object.
	 *
	 * @throws RuntimeException When an item names no object or the engine refuses the move.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function execute(array $items, array $config, array $context): array {
		$this->validateConfig(config: $config);

		$out = [];
		foreach ($items as $index => $item) {
			$json = (array)($item[FlowItems::JSON] ?? []);
			$objectId = $this->objectIdOf(json: $json, config: $config);
			$action = (string)FlowValueTemplate::render(value: $config['action'], json: $json);
			$data = (array)FlowValueTemplate::render(value: ($config['data'] ?? []), json: $json);

			try {
				$saved = $this->engine->transition(objectId: $objectId, action: $action, data: $data);
			} catch (Throwable $e) {
				throw new RuntimeException($e->getMessage(), 0, $e);
			}

			$out[] = FlowItems::item(json: $saved->jsonSerialize(), fromItemIndex: $index);
		}

		return $out;
	}//end execute()

	/**
	 * The object an item asks to move: the configured one, or the item's own.
	 *
	 * @param array<string, mixed> $json   The item's record.
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return string The object's id.
	 *
	 * @throws RuntimeException When no object can be named.
	 */
	private function objectIdOf(array $json, array $config): string {
		$self = (array)($json['@self'] ?? []);
		$objectId = trim((string)($json['uuid'] ?? ($self['id'] ?? ($json['id'] ?? ''))));

		$configured = ($config['object'] ?? null);
		if (is_string($configured) === true && trim($configured) !== '') {
			$objectId = trim((string)FlowValueTemplate::render(value: $configured, json: $json));
		}

		if ($objectId === '') {
			throw new RuntimeException($this->l10n->t('The transition step could not tell which object to move.'));
		}

		return $objectId;
	}//end objectIdOf()

	/**
	 * The BPMN kind.
	 *
	 * @return string The kind.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SERVICE_TASK;
	}//end getKind()

	/**
	 * The palette category.
	 *
	 * @return string The category.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function getCategory(): string {
		return IFlowNodeTaxonomy::CATEGORY_OBJECTS;
	}//end getCategory()
}//end class
