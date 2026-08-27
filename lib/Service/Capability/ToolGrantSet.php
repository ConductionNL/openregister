<?php

/**
 * Tool Grant Set
 *
 * RELOCATED FROM HERMIQ (ADR-099 §5), as a relocation with its tests and NOT a
 * rewrite. The capability axis — may agent X use tool T — is not agent-specific
 * and resolves against `ToolRegistryFacade`, which already lives here. Its codec
 * carries a measured scar (35 of 87 tools parsed wrong) and ADR-095's
 * persistence constraint, and a rewrite-while-moving would reopen both, so
 * nothing below this line changed except the namespace.
 *
 * 🔴 NAMED `Capability`, NEVER `Grant`. `Service\Delegation` answers a different
 * question — may principal P act as user B — and once both live in one codebase
 * the conflation risk rises rather than falls. A user approving a tool must not
 * be able to widen whose identity the agent wears.
 *
 * An agent's tool grants as a STRUCTURE — app → subject → action → tool id —
 * rather than as a list of strings every consumer has to take apart again.
 *
 * ## Why this exists
 *
 * `Agent.tools` was a `string[]` (ADR-035 Decision 4 froze that shape), so the
 * only way to answer "which app is this grant for?" or "is this a read?" was to
 * split the id and guess. Three separate spellings are in use —
 * `pipelinq.lead.search` (app.subject.action), `hermiq.listFiles` (app +
 * camelCase verb-first) and `list_registers` (bare snake verb-first) — and every
 * consumer needed its own rule for all three.
 *
 * 🔴 That guessing was measurably wrong. On the live catalogue, 35 of the 87
 * tools that declare no taxonomy parsed incorrectly in the grant matrix: five
 * inverted (`cms_create_page` became the subject "create") and thirty lost their
 * verb entirely, rendering the whole OpenRegister core as thirty one-off rows.
 * The bug was in the parser, but the parser only existed because the structure
 * had been thrown away at write time and had to be reconstructed at read time.
 *
 * The argument-scoped grant form is the same story from the other side: because
 * the shape was frozen as strings, a constraint on a tool's arguments had to
 * ride INSIDE the string as `?key=value`, giving the id a second grammar to
 * parse. A structure has somewhere to put it.
 *
 * ## The shape
 *
 * ```json
 * {
 *   "pipelinq": { "lead": { "search": ["pipelinq.lead.search"],
 *                           "get":    ["pipelinq.lead.get"] } },
 *   "hermiq":   { "file": { "list":   ["hermiq.listFiles"],
 *                           "read":   ["hermiq.readFile"] } }
 * }
 * ```
 *
 * ⚠️ THE TOOL ID IS STORED, NOT DERIVED — and that is the whole reason this
 * works where a nested map of names would not. `hermiq.listFiles` sits at
 * coordinates (hermiq, file, list); recomputing an id from those coordinates
 * gives `hermiq.file.list`, which is not a tool and never was. The coordinates
 * are for the human and the UI; the id is what dispatch uses. Storing only the
 * coordinates would reintroduce exactly the guessing this replaces.
 *
 * ⚠️ An action holds a LIST, not one entry, because one tool can be granted more
 * than once with different argument constraints. `runFlow?flowId=A` and
 * `runFlow?flowId=B` are two distinct capabilities sharing an id; a single-entry
 * map kept only the last and silently revoked the other.
 *
 * ```json
 * { "openregister": { "runFlow": { "runFlow": [
 *     { "id": "openregister.runFlow", "args": { "flowId": "A" } },
 *     { "id": "openregister.runFlow", "args": { "flowId": "B" } }
 * ] } } }
 * ```
 *
 * ## Compatibility
 *
 * A stored `string[]` still loads — {@see self::fromStored()} detects it and
 * converts. Nothing needs migrating before it works, and an agent last written
 * by an older build keeps its exact meaning. {@see self::toGrantStrings()}
 * converts back to the resolver's internal grammar, so the resolution rules,
 * their wildcard handling and their tests are untouched by this change.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Capability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/structured-tool-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Capability;

/**
 * An agent's grants, addressed by app, subject and action.
 *
 * @spec openspec/specs/structured-tool-grants/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) {@see ToolGrantCodec} is a pure,
 *   stateless grammar — a namespace for functions, not a collaborator. Injecting
 *   an instance of it into a value object whose only constructors are static
 *   factories would add a dependency to thread through every call site in order
 *   to reach the same functions, which is the cost the rule exists to prevent
 *   rather than an example of it.
 */
final class ToolGrantSet {

	/**
	 * The grants, as app => subject => action => entry.
	 *
	 * An entry is either the tool id as a string, or `{id, args}` when the grant
	 * is narrowed to particular argument values.
	 *
	 * @var array<string, array<string, array<string, array<int, string|array<string, mixed>>>>>
	 */
	private array $grants;

	/**
	 * Build from an already-structured map.
	 *
	 * @param array<string, array<string, array<string, array<int, string|array<string, mixed>>>>> $grants The structure.
	 */
	private function __construct(array $grants) {
		$this->grants = $grants;
	}//end __construct()

	/**
	 * Read whatever is stored on the agent, in either shape.
	 *
	 * ⚠️ The two shapes are told apart by whether the value is a LIST, not by a
	 * version field and not by "does any key look numeric". A legacy grant list
	 * is a JSON array, which decodes to a PHP list; the structured form is a JSON
	 * object, which does not. A version field would have to be written by
	 * something, and whatever wrote the legacy values is long gone.
	 *
	 * The first cut of this checked `is_int($key)` on any key and treated the
	 * whole value as legacy the moment it found one — so a structured map
	 * carrying a single stray numeric key was read as a list of grant strings,
	 * found no strings in it, and reported the agent as having NO TOOLS. Silent,
	 * total, and in the direction that breaks a working agent. `array_is_list()`
	 * asks the question that actually distinguishes them.
	 *
	 * @param mixed $stored The `Agent.tools` value.
	 *
	 * @return self The grants.
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md
	 */
	public static function fromStored(mixed $stored): self {
		if (is_array($stored) === false || $stored === []) {
			return new self([]);
		}

		if (array_is_list($stored) === true) {
			return self::fromGrantStrings(ids: $stored);
		}

		return new self(self::sanitise(raw: $stored));
	}//end fromStored()

	/**
	 * Build from the legacy `string[]` form.
	 *
	 * @param array<int, mixed> $ids The stored grant strings.
	 *
	 * @return self The grants.
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-the-legacy-string-shape-is-still-accepted-and-is-not-silently-rewritten
	 */
	public static function fromGrantStrings(array $ids): self {
		$grants = [];
		foreach ($ids as $id) {
			if (is_string($id) === false || $id === '') {
				continue;
			}

			[$app, $subject, $action, $entry] = ToolGrantCodec::coordinatesFor(grant: $id);

			// ⚠️ APPENDED, not assigned. One tool can be granted more than once
			// with DIFFERENT argument constraints — `runFlow?flowId=A` and
			// `runFlow?flowId=B` are two distinct capabilities sharing an id, and
			// assigning here kept only the last, silently revoking the other.
			// A bare grant alongside a constrained one is also legal and means
			// "every target", so it cannot displace its narrower sibling either.
			$existing = ($grants[$app][$subject][$action] ?? []);
			$existing[] = $entry;
			$grants[$app][$subject][$action] = $existing;
		}

		return new self($grants);
	}//end fromGrantStrings()

	/**
	 * Whether this agent has no grants at all.
	 *
	 * @return bool True when nothing is granted.
	 */
	public function isEmpty(): bool {
		return ($this->grants === []);
	}//end isEmpty()

	/**
	 * The canonical structured form, for storing on the agent.
	 *
	 * @return array<string, array<string, array<string, array<int, string|array<string, mixed>>>>> The structure.
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#scenario-a-grant-round-trips-without-losing-its-identity
	 */
	public function toStored(): array {
		return $this->grants;
	}//end toStored()

	/**
	 * The resolver's internal grant grammar.
	 *
	 * Deliberately converts BACK to strings at this one boundary rather than
	 * teaching {@see ToolGrantResolver} a second input shape. The resolution
	 * rules — wildcard expansion, `:write`, default-deny, argument constraints —
	 * are the most security-sensitive code in the app and are covered by a suite
	 * built against the string grammar. Changing where grants are STORED should
	 * not mean rewriting how they are RESOLVED in the same step.
	 *
	 * @return array<int, string> The grant strings.
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md
	 */
	public function toGrantStrings(): array {
		$out = [];
		foreach ($this->grants as $subjects) {
			foreach ($subjects as $actions) {
				foreach ($actions as $entries) {
					foreach ($entries as $entry) {
						$out[] = ToolGrantCodec::grantStringFor(entry: $entry);
					}
				}
			}
		}

		return array_values(array_unique($out));
	}//end toGrantStrings()

	/**
	 * Whether one tool id is granted, without re-deriving anything.
	 *
	 * @param string $app     The owning app.
	 * @param string $subject The thing acted on.
	 * @param string $action  The verb.
	 *
	 * @return bool True when granted.
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-tool-grants-are-a-structure-in-the-domain-and-a-list-in-storage
	 */
	public function has(string $app, string $subject, string $action): bool {
		return isset($this->grants[$app][$subject][$action]);
	}//end has()

	/**
	 * Add one grant at explicit coordinates.
	 *
	 * @param string $app     The owning app.
	 * @param string $subject The thing acted on.
	 * @param string $action  The verb.
	 * @param string $toolId  The tool id dispatch uses.
	 * @param array<string, mixed> $args Optional argument constraints.
	 *
	 * @return self A new set including this grant.
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#scenario-two-constrained-grants-for-one-tool-both-survive
	 */
	public function with(string $app, string $subject, string $action, string $toolId, array $args = []): self {
		$grants = $this->grants;
		$entry = ToolGrantCodec::entryFor(id: $toolId, args: $args);

		$existing = ($grants[$app][$subject][$action] ?? []);
		if (in_array($entry, $existing, true) === false) {
			$existing[] = $entry;
		}

		$grants[$app][$subject][$action] = $existing;

		return new self($grants);
	}//end with()

	/**
	 * Remove one grant.
	 *
	 * @param string $app     The owning app.
	 * @param string $subject The thing acted on.
	 * @param string $action  The verb.
	 *
	 * @return self A new set without this grant.
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-tool-grants-are-a-structure-in-the-domain-and-a-list-in-storage
	 */
	public function without(string $app, string $subject, string $action): self {
		$grants = $this->grants;
		unset($grants[$app][$subject][$action]);

		// Prune empties, so an agent that had its last grant removed reads as
		// unconfigured rather than as a map of empty maps — which `isEmpty()`
		// would otherwise report as configured-with-nothing.
		if (($grants[$app][$subject] ?? null) === []) {
			unset($grants[$app][$subject]);
		}

		if (($grants[$app] ?? null) === []) {
			unset($grants[$app]);
		}

		return new self($grants);
	}//end without()

	/**
	 * Every granted tool id, flat.
	 *
	 * @return array<int, string> The ids.
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#scenario-a-grant-round-trips-without-losing-its-identity
	 */
	public function toolIds(): array {
		$ids = [];
		foreach ($this->grants as $subjects) {
			foreach ($subjects as $actions) {
				foreach ($actions as $entries) {
					foreach ($entries as $entry) {
						if (is_array($entry) === true) {
							$ids[] = (string)($entry['id'] ?? '');
							continue;
						}

						$ids[] = (string)$entry;
					}
				}
			}
		}

		return array_values(array_filter(array_unique($ids)));
	}//end toolIds()





	/**
	 * Drop anything in a stored structure that is not shaped like a grant.
	 *
	 * The value arrives as decoded JSON written by a previous version of this
	 * app or by an API caller, so it is not trusted to be well formed.
	 *
	 * @param array<mixed> $raw The stored structure.
	 *
	 * @return array<string, array<string, array<string, array<int, string|array<string, mixed>>>>> The clean structure.
	 */
	private static function sanitise(array $raw): array {
		$clean = [];
		foreach ($raw as $app => $subjects) {
			if (self::isUsableLevel(key: $app, value: $subjects) === false) {
				continue;
			}

			foreach ($subjects as $subject => $actions) {
				if (self::isUsableLevel(key: $subject, value: $actions) === false) {
					continue;
				}

				$kept = self::sanitiseActions(actions: $actions);
				if ($kept !== []) {
					$clean[$app][$subject] = $kept;
				}
			}
		}

		return $clean;
	}//end sanitise()

	/**
	 * Whether one level of the stored structure is worth descending into.
	 *
	 * Both the app and the subject level ask the same two questions — is the key
	 * a usable name, and is the value something to walk — and the stored value is
	 * decoded JSON written by an older build or an API caller, so neither can be
	 * assumed.
	 *
	 * @param mixed $key   The level's key.
	 * @param mixed $value The level's value.
	 *
	 * @return bool True when it can be descended into.
	 */
	private static function isUsableLevel(mixed $key, mixed $value): bool {
		return (is_string($key) === true && $key !== '' && is_array($value) === true);
	}//end isUsableLevel()

	/**
	 * The usable entries of one subject's actions.
	 *
	 * @param array<mixed> $actions The stored actions for one subject.
	 *
	 * @return array<string, array<int, string|array<string, mixed>>> The clean actions.
	 */
	private static function sanitiseActions(array $actions): array {
		$clean = [];
		foreach ($actions as $action => $entry) {
			if (is_string($action) === false || $action === '') {
				continue;
			}

			// An action holds a LIST of entries, but a single unconstrained grant
			// may be written as the bare id — that is the common case, and
			// spelling every one of them `["id"]` in stored JSON would bury the
			// few that genuinely carry constraints.
			$candidates = $entry;
			if (is_array($entry) === false || array_is_list($entry) === false) {
				$candidates = [$entry];
			}

			$kept = [];
			foreach ($candidates as $candidate) {
				$one = ToolGrantCodec::sanitiseEntry(entry: $candidate);
				if ($one !== null) {
					$kept[] = $one;
				}
			}

			if ($kept !== []) {
				$clean[$action] = $kept;
			}
		}

		return $clean;
	}//end sanitiseActions()

}//end class
