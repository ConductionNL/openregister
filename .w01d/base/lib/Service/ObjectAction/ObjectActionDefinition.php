<?php

/**
 * One declared action on a schema, read into a value.
 *
 * ```json
 * "x-openregister-action": {
 *   "publish": {
 *     "name": "publish",
 *     "description": "Make the decision public from a date.",
 *     "label": { "nl": "Publiceren", "en": "Publish" },
 *     "surfaces": ["header", "row", "bulk"],
 *     "inputs": { "type": "object", "properties": { "from": { "type": "string", "format": "date" } } },
 *     "when": { "==": [ { "var": "object.status" }, "decided" ] },
 *     "whenMessage": "Only a decided decision can be published.",
 *     "steps": [ { "id": "date", "type": "openregister.set-fields", "config": { "set": { "publicatiedatum": "{{inputs.from}}" } } } ]
 *   }
 * }
 * ```
 *
 * The key is the action's identity: it is the word the schema's
 * `authorization` block grants. An action carries at most one body, and the
 * body is its kind: `transition`, `flow` (a macro binding; `macro: true` is
 * still read as part of it) or `steps`. An action with no body stays a verb
 * that authorization may grant and an app may check; it is never runnable.
 *
 * The shape refusals are {@see ObjectActionShape}'s; whether a bound flow
 * exists and whether inline steps name known nodes are questions about other
 * records and live in {@see ObjectActionValidator}.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

/**
 * A declared action: what it is called, where it is offered, what it takes and what it runs.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One readonly property per declared key; a
 *   bag array would lose the types every reader of the declaration relies on.
 * @SuppressWarnings(PHPMD.StaticAccess) ObjectActionShape and ObjectActionElevation are stateless
 *   readers; the save path and the read path must reach the same answer.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */
final class ObjectActionDefinition {

	/**
	 * The configuration key holding declared actions.
	 *
	 * @var string
	 */
	public const ACTION_BLOCK = 'x-openregister-action';

	/**
	 * Kind of an action whose body is a lifecycle transition.
	 *
	 * @var string
	 */
	public const KIND_TRANSITION = 'transition';

	/**
	 * Kind of an action bound to a stored flow.
	 *
	 * @var string
	 */
	public const KIND_FLOW = 'flow';

	/**
	 * Kind of an action whose body is an inline list of flow nodes.
	 *
	 * @var string
	 */
	public const KIND_STEPS = 'steps';

	/**
	 * The places a host may offer an action.
	 *
	 * @var list<string>
	 */
	public const SURFACES = ['header', 'row', 'bulk', 'detail-menu'];

	/**
	 * Where an action is offered when it does not say.
	 *
	 * @var list<string>
	 */
	public const DEFAULT_SURFACES = ['header', 'row'];

	/**
	 * Run modes.
	 *
	 * @var list<string>
	 */
	public const MODES = ['sync', 'async'];

	/**
	 * The body keys; at most one may be present.
	 *
	 * @var list<string>
	 */
	private const BODIES = [self::KIND_TRANSITION, self::KIND_FLOW, self::KIND_STEPS];

	/**
	 * Constructor.
	 *
	 * @param string                    $key         The action key, the word authorization grants.
	 * @param string|array<string,string> $label     The label, a string or a locale map.
	 * @param string|null               $description What the action does.
	 * @param string|null               $icon        The icon name.
	 * @param list<string>              $surfaces    Where a host offers it.
	 * @param array<string, mixed>|null $inputs      The input JSON Schema, or null for none.
	 * @param mixed                     $when        The JSONLogic precondition, or null.
	 * @param mixed                     $whenMessage The reason given when `when` does not hold.
	 * @param mixed                     $confirm     The confirmation text, or null.
	 * @param string                    $mode        `sync` or `async`.
	 * @param string|null               $next        The declared `next` hint, or null.
	 * @param string|null               $kind        The body kind, or null for a bodiless action.
	 * @param mixed                     $body        The body: a transition key, a flow name or uuid, or a step list.
	 * @param list<array{register: string, schema: string, verbs: list<string>}> $elevate The scoped elevations.
	 * @param bool                      $reversible  Whether a bulk run may be reversed.
	 */
	private function __construct(
		public readonly string $key,
		public readonly string|array $label,
		public readonly ?string $description,
		public readonly ?string $icon,
		public readonly array $surfaces,
		public readonly ?array $inputs,
		public readonly mixed $when,
		public readonly mixed $whenMessage,
		public readonly mixed $confirm,
		public readonly string $mode,
		public readonly ?string $next,
		public readonly ?string $kind,
		public readonly mixed $body,
		public readonly array $elevate,
		public readonly bool $reversible,
	) {
	}//end __construct()

	/**
	 * Read one declaration. The caller has already checked {@see refusalsFor()}.
	 *
	 * @param string               $key        The action key.
	 * @param array<string, mixed> $definition The declared entry.
	 *
	 * @return self The definition.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
	 */
	public static function fromDeclaration(string $key, array $definition): self {
		$kind = self::kindOf(definition: $definition);
		$body = null;
		if ($kind !== null) {
			$body = $definition[$kind];
		}

		$surfaces = self::DEFAULT_SURFACES;
		if (is_array($definition['surfaces'] ?? null) === true) {
			$surfaces = array_values(array_map('strval', $definition['surfaces']));
		}

		$inputs = null;
		if (is_array($definition['inputs'] ?? null) === true) {
			$inputs = $definition['inputs'];
		}

		$label = ($definition['label'] ?? ($definition['name'] ?? $key));
		if (is_string($label) === false && is_array($label) === false) {
			$label = $key;
		}

		return new self(
			key: $key,
			label: $label,
			description: self::stringOrNull(value: ($definition['description'] ?? null)),
			icon: self::stringOrNull(value: ($definition['icon'] ?? null)),
			surfaces: $surfaces,
			inputs: $inputs,
			when: ($definition['when'] ?? null),
			whenMessage: ($definition['whenMessage'] ?? null),
			confirm: ($definition['confirm'] ?? null),
			mode: (string)($definition['mode'] ?? 'sync'),
			next: self::stringOrNull(value: ($definition['next'] ?? null)),
			kind: $kind,
			body: $body,
			elevate: ObjectActionElevation::entriesOf(definition: $definition),
			reversible: (($definition['reversible'] ?? false) === true)
		);
	}//end fromDeclaration()

	/**
	 * The well-formed declarations of one configuration, keyed by action.
	 *
	 * A malformed entry is a refusal, not a definition: it is left out here so
	 * no caller acts on an entry the save is about to reject.
	 *
	 * @param array<string, mixed> $configuration A schema or register configuration.
	 * @param array<string, mixed> $lifecycle     The lifecycle a `transition` must name, or [] to skip that check.
	 *
	 * @return array<string, self> Action key => definition.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
	 */
	public static function parse(array $configuration, array $lifecycle = []): array {
		$definitions = [];
		foreach (self::declarations(configuration: $configuration) as $key => $definition) {
			$refusals = ObjectActionShape::refusals(key: $key, definition: $definition, lifecycle: $lifecycle, authorization: null);
			if ($refusals !== []) {
				continue;
			}

			$definitions[$key] = self::fromDeclaration(key: $key, definition: $definition);
		}

		return $definitions;
	}//end parse()

	/**
	 * Why the declared actions of a schema configuration may not be saved.
	 *
	 * @param array<string, mixed>      $configuration The schema configuration.
	 * @param array<string, mixed>|null $authorization The schema's authorization block, for `elevate`.
	 *
	 * @return list<string> The refusals, each naming its action; empty when the shape is sound.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
	 */
	public static function refusals(array $configuration, ?array $authorization = []): array {
		$lifecycle = ($configuration['x-openregister-lifecycle'] ?? []);
		if (is_array($lifecycle) === false) {
			$lifecycle = [];
		}

		$refusals = [];
		foreach (self::declarations(configuration: $configuration) as $key => $definition) {
			$refusals = array_merge(
				$refusals,
				ObjectActionShape::refusals(key: $key, definition: $definition, lifecycle: $lifecycle, authorization: ($authorization ?? []))
			);
		}

		return $refusals;
	}//end refusals()

	/**
	 * Whether this action can be run, rather than only granted.
	 *
	 * @return bool True when it carries a body.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
	 */
	public function isRunnable(): bool {
		return $this->kind !== null;
	}//end isRunnable()

	/**
	 * Whether a host may offer this action on a selection.
	 *
	 * @return bool True when `bulk` is among its surfaces.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
	 */
	public function offersBulk(): bool {
		return in_array('bulk', $this->surfaces, true);
	}//end offersBulk()

	/**
	 * The input names declared `writeOnly`, which are never stored.
	 *
	 * @return list<string> The names.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
	 */
	public function writeOnlyInputs(): array {
		$names = [];
		foreach ((array)($this->inputs['properties'] ?? []) as $name => $property) {
			if (is_array($property) === true && ObjectActionInputSchema::isWriteOnly(property: $property) === true) {
				$names[] = (string)$name;
			}
		}

		return $names;
	}//end writeOnlyInputs()

	/**
	 * This definition without its `elevate` list.
	 *
	 * An inherited register-level entry is never validated by a schema save, so
	 * it may not carry an elevation into a schema that did not declare one.
	 *
	 * @return self The definition without elevation.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
	 */
	public function withoutElevation(): self {
		return new self(
			key: $this->key,
			label: $this->label,
			description: $this->description,
			icon: $this->icon,
			surfaces: $this->surfaces,
			inputs: $this->inputs,
			when: $this->when,
			whenMessage: $this->whenMessage,
			confirm: $this->confirm,
			mode: $this->mode,
			next: $this->next,
			kind: $this->kind,
			body: $this->body,
			elevate: [],
			reversible: $this->reversible
		);
	}//end withoutElevation()

	/**
	 * The body keys a declaration carries; more than one is a refusal.
	 *
	 * @param array<string, mixed> $definition The declared entry.
	 *
	 * @return list<string> The body keys present.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
	 */
	public static function bodiesOf(array $definition): array {
		return array_values(array_filter(self::BODIES, static fn (string $body): bool => array_key_exists($body, $definition)));
	}//end bodiesOf()

	/**
	 * The body kind a declaration carries, or null when it has none.
	 *
	 * `macro: true` without `flow` has no body: that is a refusal the macro
	 * binding reports, not a kind.
	 *
	 * @param array<string, mixed> $definition The declared entry.
	 *
	 * @return string|null The kind.
	 */
	private static function kindOf(array $definition): ?string {
		foreach (self::BODIES as $body) {
			if (array_key_exists($body, $definition) === true) {
				return $body;
			}
		}

		return null;
	}//end kindOf()

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string.
	 */
	private static function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return $value;
	}//end stringOrNull()

	/**
	 * The declared entries of a configuration, keyed by action.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return array<string, array<string, mixed>> Action key => entry.
	 */
	public static function declarations(array $configuration): array {
		$declared = ($configuration[self::ACTION_BLOCK] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$definitions = [];
		foreach ($declared as $key => $definition) {
			if (is_string($key) === false || $key === '' || is_array($definition) === false) {
				continue;
			}

			$definitions[$key] = $definition;
		}

		return $definitions;
	}//end declarations()
}//end class
