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
 * @SuppressWarnings(PHPMD.StaticAccess) ObjectActionShape and ObjectActionElevation are stateless
 *   readers; the save path and the read path must reach the same answer.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */
final class ObjectActionDefinition implements ObjectActionDefinitionInterface {

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
	 * Kind of an action that sets one empty property to the caller's uid.
	 *
	 * The body is `{"field": "<property>"}`.
	 *
	 * @var string
	 */
	public const KIND_SELF_ASSIGN = 'selfAssign';

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
	 * The action key, the word authorization grants.
	 *
	 * @var string
	 */
	public readonly string $key;

	/**
	 * The label, a string or a locale map.
	 *
	 * @var string|array<string,string>
	 */
	public readonly string|array $label;

	/**
	 * What the action does.
	 *
	 * @var string|null
	 */
	public readonly ?string $description;

	/**
	 * The icon name.
	 *
	 * @var string|null
	 */
	public readonly ?string $icon;

	/**
	 * Where a host offers it.
	 *
	 * @var list<string>
	 */
	public readonly array $surfaces;

	/**
	 * The input JSON Schema, or null for none.
	 *
	 * @var array<string, mixed>|null
	 */
	public readonly ?array $inputs;

	/**
	 * The JSONLogic precondition, or null.
	 *
	 * @var mixed
	 */
	public readonly mixed $when;

	/**
	 * The reason given when `when` does not hold.
	 *
	 * @var mixed
	 */
	public readonly mixed $whenMessage;

	/**
	 * The confirmation text, or null.
	 *
	 * @var mixed
	 */
	public readonly mixed $confirm;

	/**
	 * `sync` or `async`.
	 *
	 * @var string
	 */
	public readonly string $mode;

	/**
	 * The declared `next` hint, or null.
	 *
	 * @var string|null
	 */
	public readonly ?string $next;

	/**
	 * The body kind, or null for a bodiless action.
	 *
	 * @var string|null
	 */
	public readonly ?string $kind;

	/**
	 * The body: a transition key, a flow name or uuid, or a step list.
	 *
	 * @var mixed
	 */
	public readonly mixed $body;

	/**
	 * The scoped elevations.
	 *
	 * @var list<array{register: string, schema: string, verbs: list<string>}>
	 */
	public readonly array $elevate;

	/**
	 * Whether a bulk run may be reversed.
	 *
	 * @var bool
	 */
	public readonly bool $reversible;

	/**
	 * Constructor.
	 *
	 * @param ObjectActionPresentation $presentation The key, label, description and icon; the surfaces, inputs and precondition.
	 * @param ObjectActionExecution    $execution    The body kind, body, mode and `next` hint; the confirmation, elevations and reversibility.
	 */
	private function __construct(ObjectActionPresentation $presentation, ObjectActionExecution $execution) {
		$identity          = $presentation->identity;
		$offer             = $presentation->offer;
		$run               = $execution->run;
		$policy            = $execution->policy;
		$this->key         = $identity->key;
		$this->label       = $identity->label;
		$this->description = $identity->description;
		$this->icon        = $identity->icon;
		$this->surfaces    = $offer->surfaces;
		$this->inputs      = $offer->inputs;
		$this->when        = $offer->when;
		$this->whenMessage = $offer->whenMessage;
		$this->mode        = $run->mode;
		$this->next        = $run->next;
		$this->kind        = $run->kind;
		$this->body        = $run->body;
		$this->confirm     = $policy->confirm;
		$this->elevate     = $policy->elevate;
		$this->reversible  = $policy->reversible;
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
		$presentation = new ObjectActionPresentation(
			identity: ObjectActionDeclarationReader::identity(key: $key, definition: $definition),
			offer: ObjectActionDeclarationReader::offer(definition: $definition)
		);
		$policy       = ObjectActionDeclarationReader::policy(definition: $definition, elevate: ObjectActionElevation::entriesOf(definition: $definition));

		return new self(
			presentation: $presentation,
			execution: new ObjectActionExecution(run: ObjectActionDeclarationReader::body(definition: $definition), policy: $policy)
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
			$refusals = ObjectActionShape::refusals(
				action: new ObjectActionEntry(key: $key, definition: $definition),
				rules: new SchemaRules(lifecycle: $lifecycle, authorization: null)
			);
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

		$rules    = new SchemaRules(lifecycle: $lifecycle, authorization: ($authorization ?? []));
		$refusals = [];
		foreach (self::declarations(configuration: $configuration) as $key => $definition) {
			$refusals = array_merge($refusals, ObjectActionShape::refusals(action: new ObjectActionEntry(key: $key, definition: $definition), rules: $rules));
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
	 * The property a self-assign action sets, or null for any other kind.
	 *
	 * @return string|null The property name.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	public function selfAssignField(): ?string {
		if ($this->kind !== self::KIND_SELF_ASSIGN || is_array($this->body) === false) {
			return null;
		}

		return ObjectActionSelfAssignShape::fieldOf(body: $this->body);
	}//end selfAssignField()

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
		$presentation = new ObjectActionPresentation(
			identity: new ObjectActionIdentity(key: $this->key, label: $this->label, description: $this->description, icon: $this->icon),
			offer: new ObjectActionOffer(surfaces: $this->surfaces, inputs: $this->inputs, when: $this->when, whenMessage: $this->whenMessage)
		);
		$execution    = new ObjectActionExecution(
			run: new ObjectActionBody(mode: $this->mode, next: $this->next, kind: $this->kind, body: $this->body),
			policy: new ObjectActionRunPolicy(confirm: $this->confirm, elevate: [], reversible: $this->reversible)
		);

		return new self(presentation: $presentation, execution: $execution);
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
		return ObjectActionDeclarationReader::bodiesOf(definition: $definition);
	}//end bodiesOf()

	/**
	 * The declared entries of a configuration, keyed by action.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return array<string, array<string, mixed>> Action key => entry.
	 */
	public static function declarations(array $configuration): array {
		return ObjectActionDeclarationReader::declarations(configuration: $configuration);
	}//end declarations()

}//end class
