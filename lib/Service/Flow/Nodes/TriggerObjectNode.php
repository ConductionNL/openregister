<?php

/**
 * An entry point: this flow starts when an object event fires.
 *
 * A trigger is not a step. Nothing in a run "executes" it — it is where the
 * run BEGINS, and by the time any item reaches this node the trigger has
 * already happened. It is a node so that the thing which starts a flow is
 * visible on the canvas next to everything else the flow does, and so that a
 * flow can have SEVERAL entry points, which four columns on the flow row
 * cannot express.
 *
 * ONE EVENT, ONE REGISTER, ONE SCHEMA
 * -----------------------------------
 * A trigger names exactly one event type on exactly one register/schema pair,
 * and `validateConfig()` refuses anything else. That is a deliberate narrowing,
 * for a reason that is about the DATA and not about the lookup:
 *
 *   objects of different schemas do not carry the same JSON.
 *
 * A trigger that admitted two schemas would hand the next node two different
 * shapes under one name, and the node would read the fields it knows from
 * whichever shape happened to arrive. That does not fail — it half-works, on
 * the subset of fields the two shapes share, which is the worst outcome
 * available. Normalising differing shapes is a mapping node's job, downstream,
 * where it is written down and can be read.
 *
 * A flow that must react to two schemas therefore carries two trigger nodes,
 * each with its own mapping, converging on a common path. That is more to
 * author and it is honest about what is happening.
 *
 * The narrowing also happens to make the matching cheap — an exact triple
 * rather than a set intersection — which is what lets the resolver answer
 * "which flows want this event" without opening every flow document. That is a
 * consequence, not the reason.
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
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Rules\RuleScope;

/**
 * Starts the flow when an object is created, updated or deleted.
 */
class TriggerObjectNode extends TriggerNodeBase implements TriggerObjectNodeInterface, IFlowNodeConfigForm {

	/**
	 * The object events a trigger may name.
	 *
	 * Deliberately a closed list. An event name the engine never fires is a
	 * trigger that is saved, looks configured, and can never start anything —
	 * the failure mode a trigger cannot afford, because nothing happening looks
	 * exactly like nothing needing to happen.
	 *
	 * @var array<int, string>
	 */
	public const EVENTS = [
		'object.created',
		'object.updated',
		'object.deleted',
		'object.transitioned',
	];

	/**
	 * The lists a `transition` filter may hold, each matched against the transition's own value.
	 *
	 * @var array<int, string>
	 */
	public const TRANSITION_LISTS = ['actions', 'from', 'to'];

	/**
	 * The node type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function getId(): string {
		return 'openregister.trigger-object';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('When an object changes');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Start the flow when one kind of object is created, updated or deleted. '
			. 'One event, one register, one schema — use a mapping node to normalise other shapes.'
		);

	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/play.svg');
	}//end getIcon()

	/**
	 * The config vocabulary of an object trigger.
	 *
	 * `scope` ({ property, value }) says which objects of the schema the flow
	 * is about, for the rule inventory; it does not narrow what fires.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function configKeys(): array {
		return ['event', 'register', 'schema', 'scope', 'transition', 'changedFields'];
	}//end configKeys()

	/**
	 * The event, register and schema the trigger listens to, each required.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	public function configForm(): array {
		// `scope` ({ property, value }) is a keyed structure the field vocabulary
		// cannot describe; it stays in the JSON pane, and the help below says so.
		$single = $this->l10n->t(
			'There is no "any" choice on purpose: a trigger names exactly one event, register and '
				. 'schema, so the flows an object change starts can be found without opening every flow.'
		);

		return [
			[
				'key' => 'event',
				'label' => $this->l10n->t('Event'),
				'type' => 'select',
				'help' => $this->l10n->t('Which change starts the flow. This '
					. 'trigger fires on an object being created, updated or deleted.').' '.$single,
				'required' => true,
				'optionsFrom' => '/apps/openregister/api/flow/event-catalog',
			],
			[
				'key' => 'register',
				'label' => $this->l10n->t('Register'),
				'type' => 'select',
				'help' => $single,
				'required' => true,
				'optionsFrom' => '/apps/openregister/api/registers',
			],
			[
				'key' => 'schema',
				'label' => $this->l10n->t('Schema'),
				'type' => 'select',
				'help' => $this->l10n->t('Which objects of the register the flow is about. '
					. 'To narrow further, set a scope (one property and one value) in the JSON pane.'),
				'required' => true,
				'optionsFrom' => '/apps/openregister/api/schemas',
			],
		];
	}//end configForm()

	/**
	 * All three keys are REQUIRED, and `event` must be one the engine fires.
	 *
	 * Required, not optional-with-a-default: a trigger missing its subject
	 * would either match nothing (and never fire) or match everything (and fire
	 * on every object in the instance). Neither is a defensible default, and
	 * both are silent.
	 *
	 * @param array $config The node configuration.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the trigger does not name exactly one subject, or has a malformed scope.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function validateConfig(array $config): void {
		$this->assertEvent(event: trim((string)($config['event'] ?? '')));

		foreach (['register', 'schema'] as $key) {
			if (trim((string)($config[$key] ?? '')) === '') {
				throw new InvalidArgumentException(
					sprintf(
						'An object trigger must name one "%s". A trigger with no %s either matches nothing '
						. 'and never fires, or matches everything — and both are silent.',
						$key,
						$key
					)
				);
			}
		}

		$this->assertScope(config: $config);
		$this->assertTransition(config: $config);
		$this->assertChangedFields(config: $config);
	}//end validateConfig()

	/**
	 * A `transition` filter belongs to `object.transitioned` and holds only non-empty lists of names.
	 *
	 * @param array<string, mixed> $config The trigger config.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the filter is on another event or malformed.
	 *
	 * @spec openspec/changes/flow-trigger-transitions-and-changed-fields/specs/flow-engine/spec.md#requirement-an-object-trigger-can-start-a-flow-on-a-state-change
	 */
	private function assertTransition(array $config): void {
		if (array_key_exists('transition', $config) === false) {
			return;
		}

		$this->assertFilterEvent(config: $config, key: 'transition', event: 'object.transitioned');

		$filter = $config['transition'];
		if (is_array($filter) === false || ($filter !== [] && array_is_list($filter) === true)) {
			throw new InvalidArgumentException(
				'A "transition" filter is an object of lists: {"actions": [..], "from": [..], "to": [..]}. '
				. 'An empty object starts the flow on every transition.'
			);
		}

		foreach ($filter as $list => $names) {
			if (in_array($list, self::TRANSITION_LISTS, true) === false) {
				throw new InvalidArgumentException(
					sprintf('A "transition" filter has no "%s" list; it holds: %s.', $list, implode(', ', self::TRANSITION_LISTS))
				);
			}

			$this->assertNameList(names: $names, key: 'transition.' . $list);
		}
	}//end assertTransition()

	/**
	 * A `changedFields` list belongs to `object.updated` and names properties as strings.
	 *
	 * @param array<string, mixed> $config The trigger config.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the list is on another event or malformed.
	 *
	 * @spec openspec/changes/flow-trigger-transitions-and-changed-fields/specs/flow-engine/spec.md#requirement-an-update-trigger-can-watch-named-fields
	 */
	private function assertChangedFields(array $config): void {
		if (array_key_exists('changedFields', $config) === false) {
			return;
		}

		$this->assertFilterEvent(config: $config, key: 'changedFields', event: 'object.updated');
		$this->assertNameList(names: $config['changedFields'], key: 'changedFields');
	}//end assertChangedFields()

	/**
	 * Refuse a filter on an event that cannot carry it.
	 *
	 * @param array<string, mixed> $config The trigger config.
	 * @param string               $key    The filter key.
	 * @param string               $event  The one event the filter belongs to.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the trigger names another event.
	 */
	private function assertFilterEvent(array $config, string $key, string $event): void {
		if (trim((string)($config['event'] ?? '')) !== $event) {
			throw new InvalidArgumentException(
				sprintf('"%s" filters %s only; this trigger names "%s".', $key, $event, trim((string)($config['event'] ?? '')))
			);
		}
	}//end assertFilterEvent()

	/**
	 * Refuse a list that is empty, not a list, or holds anything but non-blank strings.
	 *
	 * @param mixed  $names The list.
	 * @param string $key   The key it sits under, for the message.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the list is not a non-empty list of names.
	 */
	private function assertNameList(mixed $names, string $key): void {
		if (is_array($names) === false || $names === [] || array_is_list($names) === false) {
			throw new InvalidArgumentException(
				sprintf('"%s" must be a non-empty list of names. Leave it out to match every value.', $key)
			);
		}

		foreach ($names as $name) {
			if (is_string($name) === false || trim($name) === '') {
				throw new InvalidArgumentException(sprintf('"%s" holds only non-blank names.', $key));
			}
		}
	}//end assertNameList()

	/**
	 * Refuse a missing event, or one the engine never fires.
	 *
	 * @param string $event The trimmed `event`.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the event is empty or unknown.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	private function assertEvent(string $event): void {
		if ($event === '') {
			throw new InvalidArgumentException(
				'An object trigger must name an "event" — one of: ' . implode(', ', self::EVENTS)
			);
		}

		if (in_array($event, self::EVENTS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('Unknown trigger event "%s". The engine fires: %s.', $event, implode(', ', self::EVENTS))
			);
		}
	}//end assertEvent()

	/**
	 * Refuse a declared `scope` that is not one property and one scalar value.
	 *
	 * @param array $config The node configuration.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the scope is malformed.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function assertScope(array $config): void {
		if (array_key_exists('scope', $config) === true
			&& (new RuleScope())->fromDeclaration(declaration: $config) === null
		) {
			throw new InvalidArgumentException(
				'An object trigger scope names one "property" and one scalar "value", for example '
				. '{"property": "caseType", "value": "<uuid>"}.'
			);
		}
	}//end assertScope()
}//end class
