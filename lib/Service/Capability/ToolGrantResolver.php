<?php

/**
 * Tool Grant Resolver (agent-tool-governance-and-disclosure,
 * hermiq-prefer-tool-hints).
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
 * Pure resolution of `Agent.tools` grant entries against the ADR-063 derived MCP
 * catalog `ToolRegistryFacade::listTools([])` returns. `Agent.tools` stays a plain
 * `string[]` (ADR-035 Decision 4 froze the shape) but the grammar of each entry is
 * extended:
 *
 * - `{app}.{tool}` / `{app}.{schema}.{verb}` (no trailing `.*`) — an EXACT id,
 *   passed through verbatim (today's behaviour, including write verbs named
 *   explicitly).
 * - `{app}.{schema}.*` — a schema wildcard; expands to that schema's READ verbs
 *   only (`search`, `get`) found in the catalog — default-deny on writes.
 * - `{app}.{schema}.*:write` — the same wildcard, but also expands the schema's
 *   write verbs (`create`, `update`, `delete`) found in the catalog.
 * - `[]` / unset `Agent.tools` — **NO TOOLS**. This used to mean "all discovered
 *   tools allowed, minus write/destructive". Measured 2026-08-16 that default gave
 *   an agent 81 tools / ~101,000 tokens on every turn — over half a 200K context
 *   window — and 89% of agents on the instance were taking it because nobody had
 *   filled the field in. It also widened by itself as apps were installed, which is
 *   a grant nobody made. THERE IS NO OVERRIDE — an unconfigured agent is
 *   unconfigured, and configuring it is the only way to give it tools.
 * - `{app}.{tool}?arg=value&other=in:a,b,c` — an ARGUMENT-SCOPED grant
 *   (hydra-console-agent-leaves): the exact id BEFORE the `?`, narrowed by one or
 *   more constraints on the arguments the tool is invoked with. `key=value` PINS
 *   an argument to one literal; `key=in:a,b,c` declares a CLOSED value set. The
 *   grant resolves to the SAME catalog id as the bare exact-id form — no second
 *   catalog entry is invented and no descriptor is rewritten — and the constraints
 *   are carried to `FacadeToolInvoker`, the single dispatch chokepoint, where they
 *   are enforced BEFORE the facade call. This is what makes a single multi-target
 *   tool (one that picks its target from an argument, e.g. OpenRegister's
 *   `openregister.runFlow` selecting a flow by `flowId`) grantable as ONE specific
 *   capability instead of as its whole target space. An UNCONSTRAINED exact-id
 *   grant for such a tool stays legal and means EVERY target. Narrowing never
 *   downgrades classification: a write/destructive tool stays write/destructive
 *   for default-deny, dry-run and approval purposes, because classification reads
 *   the BASE id and its descriptor and never looks at the constraints.
 *
 * The argument-scoped form is strictly ADDITIVE: a grant string containing no `?`
 * is split, expanded and classified byte-for-byte as before, so every stored
 * `Agent.tools` value keeps its current meaning and no migration is required
 * (ADR-035 Decision 4 froze the `string[]` shape, so the constraint rides INSIDE
 * the string rather than beside it).
 *
 * **Classification precedence** (`isWriteOrDestructive()`), most-authoritative first:
 *
 * 1. **Declared descriptor hints** — `scope` (closed vocabulary, no boolean
 *    ambiguity), then `destructiveHint`, then `readOnlyHint` — the first one the
 *    descriptor actually sets wins; the others are not consulted. OpenRegister's
 *    `McpProviderBridge::getFunctions()` forwards these ADR-063 MCP annotation
 *    keys onto the LLPhant descriptor additively when the provider (a schema's
 *    `x-openregister-mcp` dialect, or a `#[McpTool(readOnlyHint:, ...)]`-annotated
 *    service tool) set them (OpenRegister PR #369 closed the forwarding gap this
 *    class used to document as open; verified forwarding present against HEAD
 *    2026-07-13 — `openregister` `10e605cea`). A key is omitted entirely, never
 *    defaulted, when the provider didn't set it.
 * 2. **Verb-suffix fallback** — only when the descriptor is absent or sets none
 *    of the three hint keys: the CLOSED, fixed ADR-063 verb vocabulary
 *    (`search`/`get`/`create`/`update`/`delete` —
 *    `OCA\OpenRegister\Service\Mcp\McpAnnotationValidator::VERBS`) read off a
 *    3-segment `{app}.{schema}.{verb}` id's own text — unchanged from this
 *    class's original (pre-hints) behaviour, preserved exactly for un-annotated
 *    derived tools (design.md "Declarative vs Imperative": the *rule* is code,
 *    the *inputs* — grants and the catalog — are declarative).
 * 3. **Fail closed on anything else** — a hint-less id that isn't a 3-segment
 *    derived id (a bare/2-segment hand-written, curated, or legacy id) is
 *    classified write/destructive. This is a DELIBERATE reversal of this class's
 *    pre-hints behaviour, where such an id was NEVER classified this way (see
 *    `hermiq-prefer-tool-hints` design.md "Why fail closed, and why now" — a
 *    curated 2-segment tool like `pipelinq.createLead` is exactly where the
 *    dangerous operations live, and was previously unclassifiable, so it could
 *    never trip default-deny or the approval gate).
 *
 * Hints are ADVISORY UX/classification metadata only — OpenRegister RBAC and the
 * `human-approval-gate` approval gate stay the sole authoritative invoke-time
 * boundary; a `readOnlyHint:true` (or a `scope:read`) descriptor can never bypass
 * either.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Capability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-1
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools
 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-declared-hint-overrides-a-conflicting-verb-suffix
 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-hint-less-curated-tool-fails-closed
 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Capability;

/**
 * Expands `Agent.tools` grant strings against the derived catalog and applies
 * default-deny to write/destructive-classified tools.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The complexity is the grant GRAMMAR:
 *   five grant forms, a three-step classification precedence, and the argument-constraint
 *   parse/check. Each is a small, single-purpose, independently-tested method, and the
 *   grammar has exactly one home on purpose — splitting it would leave two places that
 *   interpret a grant string, which is how a resolver and an enforcer drift apart.
 *
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-1
 */
class ToolGrantResolver {

	/**
	 * The grant entry meaning "this agent is INTENTIONALLY tool-less".
	 *
	 * Since an empty `Agent.tools` now ALSO yields no tools, this sentinel is no
	 * longer the only way to spell "tool-less". It remains meaningful as an
	 * explicit DECLARATION: it says a human decided this agent has no tools, where
	 * an empty list only says nobody has decided anything yet.
	 *
	 * What it still does is separate a deliberate no-tools agent from one whose
	 * grants resolve to zero by ACCIDENT (a typo, an id from a stale catalog).
	 * Both end up with an empty function list; only the second is a defect, and
	 * `resolvesToNothing()` is how callers tell them apart instead of silently
	 * treating a broken agent as a chat-only one.
	 *
	 * @var string
	 */
	public const NO_TOOLS_SENTINEL = '__none__';

	/**
	 * The ADR-063 read-verb vocabulary (`McpAnnotationValidator::VERBS` subset).
	 *
	 * @var array<int, string>
	 */
	public const READ_VERBS = ['search', 'get'];

	/**
	 * The ADR-063 write/destructive-verb vocabulary.
	 *
	 * @var array<int, string>
	 */
	public const WRITE_VERBS = ['create', 'update', 'delete'];

	/**
	 * Opens the argument-constraint list on an argument-scoped grant
	 * (`{toolId}?arg=value`). Query-string form was chosen over a JSON blob
	 * because `Agent.tools` is a `string[]` an operator edits in a plain form
	 * field, and over a bare `#` fragment because the two constraint kinds must
	 * be distinguishable at a glance in a diff (design.md "Grant syntax").
	 *
	 * @var string
	 */
	public const CONSTRAINT_OPENER = '?';

	/**
	 * Optional trailing fragment marking a grant entry as approval-waived,
	 * giving the grammar `{toolId}[?{constraints}][#noapproval]`.
	 *
	 * Matched as an EXACT suffix, never a prefix or a substring: `#noapprovalX`
	 * is not a waiver, and stays part of the id — where it matches no catalogue
	 * tool and so grants nothing. A near-miss that silently granted the tool
	 * WITHOUT the waiver would be the wrong failure; a near-miss that silently
	 * granted it WITH one would be much worse.
	 *
	 * @var string
	 */
	public const WAIVER_FRAGMENT = '#noapproval';

	/**
	 * Separates one argument constraint from the next.
	 *
	 * @var string
	 */
	public const CONSTRAINT_SEPARATOR = '&';

	/**
	 * Marks a constraint value as a CLOSED SET rather than a pinned literal:
	 * `label=in:a,b,c`.
	 *
	 * @var string
	 */
	public const CONSTRAINT_SET_PREFIX = 'in:';

	/**
	 * Constraint mode: the argument must equal one literal value exactly.
	 *
	 * @var string
	 */
	public const CONSTRAINT_MODE_PIN = 'pin';

	/**
	 * Constraint mode: the argument must be a member of a closed value set.
	 *
	 * @var string
	 */
	public const CONSTRAINT_MODE_SET = 'set';

	/**
	 * Resolve `Agent.tools` grants into a concrete tool id whitelist.
	 *
	 * @param array<int, string> $grants Raw `Agent.tools` entries (exact ids,
	 *                                   schema wildcards, verb subsets, `:write`
	 *                                   modifiers — see class docblock).
	 * @param array<int, array<string,mixed>> $catalog Full descriptor list, e.g. from
	 *                                                 `ToolRegistryFacade::listTools([])`.
	 *
	 * @return array<int, string> The resolved, default-denied whitelist.
	 *
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-a-schema-wildcard-grants-read-verbs-only
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-a-write-tool-is-granted-only-when-named-explicitly
	 */
	public function resolve(array $grants, array $catalog): array {
		$descriptorsById = $this->descriptorsById(catalog: $catalog);
		$catalogIds = array_keys($descriptorsById);
		$cleanGrants = $this->sanitizeGrants(grants: $grants);

		if ($cleanGrants === []) {
			// AN AGENT WITH NO CONFIGURED TOOLS GETS NONE.
			//
			// This used to fall through to "all discovered tools allowed", relying
			// on `applyDefaultDeny()` to strip the write verbs. Measured on the
			// development instance 2026-08-16, that default was neither small nor
			// rare:
			//
			//   full catalog                       122 tools  433,198 B  ~108,300 tok
			//   what the legacy default yielded      81 tools  403,904 B  ~101,000 tok
			//   an agent with 10 explicit grants     10 tools    7,777 B  ~  1,900 tok
			//   an agent with  3 explicit grants      3 tools    2,412 B  ~    600 tok
			//
			// 89 of 111 agents had `tools` NULL and 10 more had `[]` — 89% of them
			// took this branch and received ~101,000 tokens of tool definitions on
			// every turn, over half a 200K context window, before the user said
			// anything. The write-verb strip barely helped: write tools are the
			// CHEAP ones (no outputSchema), so denying them removed 7% of the bytes
			// while leaving the expensive read verbs in place.
			//
			// Deny is also the safer direction on its own terms. "Unconfigured"
			// meaning "may use everything discovered on the instance" is a grant
			// nobody made, widening automatically as apps are installed.
			//
			// THERE IS NO OVERRIDE, deliberately. An earlier revision of this
			// change shipped `HERMIQ_LEGACY_UNSCOPED_TOOLS=1` as an escape hatch.
			// From a security-by-design position that hatch was the defect rather
			// than the mitigation: it is invisible (nothing in the product shows
			// it is set, so an agent's true capability cannot be read from the
			// agent) and it is unscoped (setting it to unblock ONE agent widens
			// every unconfigured agent on the instance).
			//
			// Text-only, NOT an error. `resolvesToNothing()` deliberately still
			// returns false for this case, because ToolLoop THROWS on it: routing
			// unconfigured agents through that would have turned a tool-scoping
			// change into 99 broken agents. An agent with no tools is a legitimate
			// conversational agent; an agent whose CONFIGURED grants resolve to
			// nothing is broken, and only the second is an exception.
			return [];
		}

		// A tool has two names; canonicalise to the one the catalogue leads with.
		//
		// `McpProviderBridge` emits a dotted `mcpId` (`pipelinq.lead.get`) and an
		// underscored `name` alias (`pipelinq_lead_get`), and the agent editor's
		// own endpoint shows an operator the UNDERSCORED one. `expandGrant()`
		// returns an exact grant verbatim, so an underscored grant stayed
		// underscored and every downstream comparison against a dotted catalogue
		// id missed — the tool-catalogue annotation reported ZERO granted tools
		// for an agent that had just been given three (measured 2026-08-17).
		//
		// Mapping both aliases onto the descriptor's primary id here means the
		// rest of the system sees exactly one id space, whichever form the
		// operator wrote.
		$canonical = [];
		foreach ($descriptorsById as $id => $descriptor) {
			$canonical[$id] = ($descriptor['mcpId'] ?? ($descriptor['name'] ?? $id));
		}

		$resolved = [];
		foreach ($cleanGrants as $grant) {
			foreach ($this->expandGrant(grant: $grant, catalogIds: $catalogIds) as $id) {
				$resolved[($canonical[$id] ?? $id)] = true;
			}
		}

		// `array_keys()` already returns a list — the array_values() this
		// replaces was a no-op.
		return array_keys($resolved);
	}//end resolve()

	/**
	 * Whether these grants say "no tools" ON PURPOSE — i.e. every entry is the
	 * `__none__` sentinel.
	 *
	 * @param array<int, string> $grants Raw `Agent.tools` entries.
	 *
	 * @return bool True when the agent is deliberately tool-less.
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less
	 */
	public function isExplicitNoTools(array $grants): bool {
		$clean = $this->sanitizeGrants(grants: $grants);
		if ($clean === []) {
			// An empty grant list means "all discovered tools" — the opposite.
			return false;
		}

		foreach ($clean as $grant) {
			if ($grant !== self::NO_TOOLS_SENTINEL) {
				return false;
			}
		}

		return true;
	}//end isExplicitNoTools()

	/**
	 * Whether a grant set asked for tools but produced NONE — the misconfiguration
	 * an agent cannot detect for itself.
	 *
	 * True only when the agent named at least one grant, did not use the
	 * `__none__` sentinel, and resolution still came back empty. That combination
	 * is never a legitimate state: every id was unknown to the catalog (a typo, a
	 * renamed tool, an id from a UI offering a different id space), so the agent
	 * silently loses every capability it was configured with. `[]` grants ("all,
	 * default-denied") and `['__none__']` ("none, deliberately") are both
	 * legitimate and return false.
	 *
	 * @param array<int, string> $grants Raw `Agent.tools` entries.
	 * @param array<int, mixed> $resolvedTools The functions resolution actually produced.
	 *
	 * @return bool True when the grants are broken and the caller must not degrade silently.
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less
	 */
	public function resolvesToNothing(array $grants, array $resolvedTools): bool {
		if ($resolvedTools !== []) {
			return false;
		}

		if ($this->sanitizeGrants(grants: $grants) === []) {
			return false;
		}

		return ($this->isExplicitNoTools(grants: $grants) === false);
	}//end resolvesToNothing()

	/**
	 * The BASE tool ids a grant list names, with any argument constraints stripped.
	 *
	 * `ToolLoop` passes a plain (non-wildcard, non-empty) whitelist straight to
	 * `ToolRegistryFacade::listTools()`, which matches on catalog ids — so an
	 * argument-scoped grant string must be reduced to its underlying id first, or
	 * it would match nothing and the agent would silently lose the capability. The
	 * constraints themselves travel separately, via `argumentConstraints()`, to the
	 * dispatch chokepoint that can actually see the arguments.
	 *
	 * A grant with no `?` is returned verbatim, so this is a no-op for every
	 * pre-existing grant form.
	 *
	 * @param array<int, string> $grants Raw `Agent.tools` entries.
	 *
	 * @return array<int, string> The same grants with constraints stripped, de-duplicated,
	 *                            original order preserved.
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-an-argument-scoped-grant-resolves-to-the-underlying-tool
	 */
	public function baseToolIds(array $grants): array {
		$ids = [];
		foreach ($this->sanitizeGrants(grants: $grants) as $grant) {
			[$base] = $this->splitGrant(grant: $grant);
			$ids[$base] = true;
		}

		// `array_keys()` already returns a list — the array_values() this
		// replaces was a no-op.
		return array_keys($ids);
	}//end baseToolIds()

	/**
	 * The argument constraints a grant list declares, keyed by the tool id they
	 * narrow.
	 *
	 * Each tool id maps to a LIST of alternative constraint sets — one per grant
	 * entry naming that id. An invocation conforms when it satisfies AT LEAST ONE
	 * of them, which is what keeps a multi-constraint grant's arguments PAIRED:
	 * `runFlow?flowId=A&label=x` plus `runFlow?flowId=B&label=y` permits (A,x) and
	 * (B,y) but NOT (A,y). Merging the constraints per argument instead would have
	 * silently widened the grant, which is the exact failure this whole form exists
	 * to prevent.
	 *
	 * A BARE exact-id grant for the same tool contributes an EMPTY constraint set,
	 * which trivially conforms — an unconstrained grant for a multi-target tool is
	 * legal and means every target, so it must not be narrowed by a sibling grant.
	 *
	 * Only ids that at least one grant constrains appear in the result; a tool no
	 * grant mentions is absent, and `violationFor()` then imposes nothing.
	 *
	 * @param array<int, string> $grants Raw `Agent.tools` entries.
	 *
	 * @return array<string, array<int, array<string, array{mode:string, values:array<int,string>}>>>
	 *                                                                                                Tool id => list of alternative constraint sets.
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-argument-constraints-on-a-grant-are-enforced-at-invocation
	 */
	public function argumentConstraints(array $grants): array {
		$constrained = [];
		$sets = [];

		foreach ($this->sanitizeGrants(grants: $grants) as $grant) {
			[$base, $query] = $this->splitGrant(grant: $grant);
			if ($this->isWildcardGrant(grant: $base) === true) {
				// A wildcard cannot be argument-scoped: the constraints would have
				// to apply to ids only the catalog knows. `expandGrant()` drops
				// such a grant entirely (fail closed), so record nothing here.
				continue;
			}

			$parsed = [];
			if ($query !== null) {
				$parsed = $this->parseConstraints(query: $query);
				$constrained[$base] = true;
			}

			$sets[$base][] = $parsed;
		}

		$out = [];
		foreach ($sets as $id => $alternatives) {
			if (isset($constrained[$id]) === false) {
				continue;
			}

			$out[$id] = $alternatives;
		}

		return $out;
	}//end argumentConstraints()

	/**
	 * Check an invocation's arguments against a tool's alternative constraint sets.
	 *
	 * PURE — it decides, it does not refuse: the refusal, its structured error and
	 * its audit line belong to `FacadeToolInvoker`, the one dispatch chokepoint.
	 * The grammar lives here because the grammar is this class's job; the
	 * enforcement lives there because that is where the arguments exist.
	 *
	 * @param array<int, array<string, array>> $constraintSets The alternative constraint sets for this
	 *                                                         tool, from `argumentConstraints()`; each is
	 *                                                         an `argument => {mode, values}` map.
	 * @param array<string, mixed> $arguments The decoded tool-call arguments.
	 *
	 * @return array{argument:string, mode:string, values:array<int,string>}|null The first
	 *                                                                            violated constraint (of the first
	 *                                                                            alternative), or null when the call
	 *                                                                            conforms — including when no constraint
	 *                                                                            set was declared at all.
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-a-pinned-argument-that-differs-is-refused-before-dispatch
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-a-value-outside-a-closed-set-is-refused
	 */
	public static function violationFor(array $constraintSets, array $arguments): ?array {
		if ($constraintSets === []) {
			return null;
		}

		$firstViolation = null;
		foreach ($constraintSets as $set) {
			$violation = self::violationForSet(set: $set, arguments: $arguments);
			if ($violation === null) {
				// This alternative is satisfied — the call is permitted.
				return null;
			}

			if ($firstViolation === null) {
				$firstViolation = $violation;
			}
		}

		return $firstViolation;
	}//end violationFor()

	/**
	 * The first constraint in ONE set that `$arguments` does not satisfy.
	 *
	 * An argument the set does not mention is left to the tool's own validation. An
	 * argument the set DOES mention but the call omits is a violation, not a pass:
	 * a pin that can be skipped by leaving the argument out is not a pin.
	 *
	 * @param array<string, array{mode:string, values:array<int,string>}> $set One constraint set.
	 * @param array<string, mixed> $arguments The call's arguments.
	 *
	 * @return array{argument:string, mode:string, values:array<int,string>}|null
	 */
	private static function violationForSet(array $set, array $arguments): ?array {
		foreach ($set as $argument => $constraint) {
			$values = $constraint['values'];

			if (array_key_exists($argument, $arguments) === false) {
				return ['argument' => $argument, 'mode' => $constraint['mode'], 'values' => $values];
			}

			$value = $arguments[$argument];
			if (is_scalar($value) === false && $value !== null) {
				// A structured value cannot satisfy a scalar constraint — fail closed
				// rather than stringify it into an accidental match.
				return ['argument' => $argument, 'mode' => $constraint['mode'], 'values' => $values];
			}

			if (in_array(self::scalarToString(value: $value), $values, true) === false) {
				return ['argument' => $argument, 'mode' => $constraint['mode'], 'values' => $values];
			}
		}

		return null;
	}//end violationForSet()

	/**
	 * Render a scalar argument value as the string a constraint compares against.
	 *
	 * Booleans become `true`/`false` rather than `1`/``, so a grant can pin a
	 * boolean argument readably and an unset-looking empty string can never
	 * accidentally satisfy a `false` pin.
	 *
	 * @param mixed $value The scalar (or null) argument value.
	 *
	 * @return string
	 */
	private static function scalarToString(mixed $value): string {
		if (is_bool($value) === true) {
			if ($value === true) {
				return 'true';
			}

			return 'false';
		}

		if ($value === null) {
			return '';
		}

		return (string)$value;
	}//end scalarToString()

	/**
	 * Split a grant into its base id and its raw constraint query, if any.
	 *
	 * Splits on the FIRST `?` only, so a constraint value may itself contain one.
	 *
	 * 🔴 The `#noapproval` fragment is stripped FIRST, before the `?` split.
	 * Order is the whole correctness argument here: `mail.send?to=in:a,b#noapproval`
	 * split the other way round yields a closed set whose last member is
	 * `b#noapproval` — a constraint that can never be satisfied, silently
	 * disabling the grant the owner thought they had widened. Doing it here, in
	 * the ONE place every caller reaches the `?`, is why `baseToolIds()`,
	 * `argumentConstraints()`, `hasWildcardGrant()` and `expandGrant()` all get
	 * the fragment handled without each remembering to.
	 *
	 * @param string $grant The grant entry.
	 *
	 * @return array{0:string, 1:string|null} `[baseId, query|null]`.
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-a-grant-may-carry-a-noapproval-waiver-fragment-parsed-before-any-other-grant-parsing
	 */
	private function splitGrant(string $grant): array {
		[$grant] = self::splitWaiver(grant: $grant);

		$position = strpos($grant, self::CONSTRAINT_OPENER);
		if ($position === false) {
			return [$grant, null];
		}

		$base = substr($grant, 0, $position);
		$query = substr($grant, ($position + 1));
		if ($query === '') {
			// `id?` with nothing after it declares no constraint — identical to the
			// bare exact-id grant rather than a grant that can never be satisfied.
			return [$base, null];
		}

		return [$base, $query];
	}//end splitGrant()

	/**
	 * Split a trailing `#noapproval` fragment off a grant entry.
	 *
	 * @param string $grant The raw grant entry.
	 *
	 * @return array{0:string, 1:bool} `[grantWithoutFragment, waived]`.
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-a-grant-may-carry-a-noapproval-waiver-fragment-parsed-before-any-other-grant-parsing
	 */
	private static function splitWaiver(string $grant): array {
		if (str_ends_with($grant, self::WAIVER_FRAGMENT) === false) {
			return [$grant, false];
		}

		return [substr($grant, 0, (0 - strlen(self::WAIVER_FRAGMENT))), true];
	}//end splitWaiver()

	/**
	 * The constraint sets belonging to WAIVED grant entries only, keyed by tool id.
	 *
	 * Shaped exactly like `argumentConstraints()` so the same pure
	 * `violationFor()` decides conformance against it — but populated only from
	 * entries that carried the fragment, and including the empty set contributed
	 * by a bare `{toolId}#noapproval`.
	 *
	 * 🔴 Waivers are per ENTRY, not per tool. Given `runFlow?flowId=A#noapproval`
	 * alongside `runFlow?flowId=B`, a call with `flowId=B` is granted and
	 * conforming, and is NOT waived — it still meets a human. Collapsing this to
	 * "the tool is waived" would let one narrow waiver silently cover every other
	 * grant for the same tool, which is the widening this whole form exists to
	 * prevent. That is why the alternatives are kept apart here rather than
	 * merged into a per-tool boolean.
	 *
	 * The returned map is `tool id => list of waived alternative constraint sets`.
	 *
	 * @param array<int, string> $grants Raw `Agent.tools` entries.
	 *
	 * @return array<string, array<int, array<string, array{mode:string, values:array<int,string>}>>>
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-the-waiver-suppresses-the-approval-gate-and-nothing-else
	 */
	public function waivedConstraintSets(array $grants): array {
		$sets = [];
		foreach ($this->sanitizeGrants(grants: $grants) as $grant) {
			[$stripped, $waived] = self::splitWaiver(grant: $grant);
			if ($waived === false) {
				continue;
			}

			[$base, $query] = $this->splitGrant(grant: $stripped);
			if ($this->isWildcardGrant(grant: $base) === true) {
				// A waived wildcard would hand the model unattended use of every
				// id the catalogue happens to contain, including ones added
				// after the owner wrote the grant. `expandGrant()` already
				// refuses a CONSTRAINED wildcard for the same reason; a waived
				// one is the same mistake with a worse blast radius.
				continue;
			}

			$parsed = [];
			if ($query !== null) {
				$parsed = $this->parseConstraints(query: $query);
			}

			$sets[$base][] = $parsed;
		}//end foreach

		return $sets;
	}//end waivedConstraintSets()

	/**
	 * Whether a specific invocation is covered by a waived grant entry.
	 *
	 * 🔴 The absent-tool guard is load-bearing and deliberately first.
	 * `violationFor()` treats an EMPTY list of alternatives as "conforms" —
	 * correct there, because a tool no grant constrains is unconstrained. Read
	 * through this method that same `null` would mean "waived", so a tool with
	 * NO waiver at all would come back waived, and the fragment would stop being
	 * a per-grant opt-in and start being the default. The two questions share a
	 * return value and mean opposite things; this guard is where they separate.
	 *
	 * @param array<string, array<int, array<string, array>>> $waivedSets From `waivedConstraintSets()`.
	 * @param string $toolId The invoked tool id.
	 * @param array<string, mixed> $arguments The decoded tool-call arguments.
	 *
	 * @return bool True when a waived entry covers this exact invocation.
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#scenario-a-waived-granted-conforming-invocation-runs-without-an-approval
	 */
	public static function waives(array $waivedSets, string $toolId, array $arguments): bool {
		$alternatives = ($waivedSets[$toolId] ?? []);
		if ($alternatives === []) {
			return false;
		}

		return (self::violationFor(constraintSets: $alternatives, arguments: $arguments) === null);
	}//end waives()

	/**
	 * Parse a grant's raw constraint query into an `argument => constraint` map.
	 *
	 * `key=value` pins; `key=in:a,b,c` declares a closed set. Keys and values are
	 * percent-decoded, so a value containing `&` or `,` can be expressed. An entry
	 * with no `=`, or an empty key, is DROPPED — a constraint nobody can satisfy
	 * would silently disable the grant, and a constraint nobody can violate would
	 * silently widen it; dropping the malformed entry leaves the well-formed ones
	 * enforced.
	 *
	 * @param string $query The raw constraint query (everything after the `?`).
	 *
	 * @return array<string, array{mode:string, values:array<int,string>}>
	 */
	private function parseConstraints(string $query): array {
		$constraints = [];
		foreach (explode(self::CONSTRAINT_SEPARATOR, $query) as $pair) {
			if (str_contains($pair, '=') === false) {
				continue;
			}

			[$rawKey, $rawValue] = explode('=', $pair, 2);

			$key = rawurldecode(trim($rawKey));
			if ($key === '') {
				continue;
			}

			$constraints[$key] = $this->parseConstraintValue(rawValue: $rawValue);
		}

		return $constraints;
	}//end parseConstraints()

	/**
	 * Parse one constraint's right-hand side into its mode and permitted values.
	 *
	 * @param string $rawValue The raw (still percent-encoded) value.
	 *
	 * @return array{mode:string, values:array<int,string>}
	 */
	private function parseConstraintValue(string $rawValue): array {
		if (str_starts_with($rawValue, self::CONSTRAINT_SET_PREFIX) === false) {
			return [
				'mode' => self::CONSTRAINT_MODE_PIN,
				'values' => [rawurldecode($rawValue)],
			];
		}

		$members = explode(',', substr($rawValue, strlen(self::CONSTRAINT_SET_PREFIX)));

		$values = [];
		foreach ($members as $member) {
			$decoded = rawurldecode($member);
			if ($decoded === '' || in_array($decoded, $values, true) === true) {
				continue;
			}

			$values[] = $decoded;
		}

		return ['mode' => self::CONSTRAINT_MODE_SET, 'values' => $values];
	}//end parseConstraintValue()

	/**
	 * Whether any grant entry uses the `{app}.{schema}.*` (or `.*:write`) wildcard
	 * form — used by `ToolLoop` to decide whether the full catalog must be fetched
	 * to expand grants (an exact-id-only whitelist never needs it).
	 *
	 * @param array<int, string> $grants Raw `Agent.tools` entries.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-2
	 */
	public function hasWildcardGrant(array $grants): bool {
		foreach ($this->sanitizeGrants(grants: $grants) as $grant) {
			[$base] = $this->splitGrant(grant: $grant);
			if ($this->isWildcardGrant(grant: $base) === true) {
				return true;
			}
		}

		return false;
	}//end hasWildcardGrant()

	/**
	 * Whether a fully-namespaced tool id is classified write/destructive.
	 *
	 * Precedence (see class docblock "Classification precedence"): a supplied
	 * descriptor's `scope`/`destructiveHint`/`readOnlyHint` hint wins when
	 * present; otherwise a 3-segment `{app}.{schema}.{verb}` id falls back to the
	 * ADR-063 verb-suffix heuristic (verb `create`/`update`/`delete`); any other
	 * hint-less shape (bare/2-segment hand-written, curated, or legacy id) FAILS
	 * CLOSED — classified write/destructive, requiring an explicit grant and
	 * tripping the approval gate, rather than silently passing as read (the
	 * pre-`hermiq-prefer-tool-hints` behaviour left these unclassifiable, which
	 * meant a curated write tool like `pipelinq.createLead` could never be
	 * default-denied or gated — see `hermiq-prefer-tool-hints` design.md).
	 *
	 * @param string $id A tool id (the `mcpId`/dotted form).
	 * @param array<string,mixed>|null $descriptor The catalog descriptor for `$id`, when
	 *                                             available (carries the optional
	 *                                             `scope`/`destructiveHint`/`readOnlyHint`
	 *                                             keys). Null when no descriptor is
	 *                                             available for this id (e.g. a call the
	 *                                             LLM attempted outside its resolved
	 *                                             catalog) — falls straight to the
	 *                                             verb-suffix/fail-closed rules.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-declared-hint-overrides-a-conflicting-verb-suffix
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-hint-less-curated-tool-fails-closed
	 */
	public static function isWriteOrDestructive(string $id, ?array $descriptor = null): bool {
		if ($descriptor !== null) {
			$fromHints = self::classifyFromHints(descriptor: $descriptor);
			if ($fromHints !== null) {
				return $fromHints;
			}
		}

		$parts = explode('.', $id);
		if (count($parts) === 3) {
			return in_array(end($parts), self::WRITE_VERBS, true);
		}

		// Hint-less, non-3-segment id: unclassifiable by any positive signal —
		// fail CLOSED rather than silently pass as read (hermiq-prefer-tool-hints).
		return true;
	}//end isWriteOrDestructive()

	/**
	 * Whether a tool needs an EXPLICIT grant — the union of the CRUD rule and
	 * the reach rule.
	 *
	 * 🔴 A UNION, never a replacement. Reach may only ever ADD tools to the
	 * gated set: a `self` reach must not un-gate something the existing
	 * `scope`/`destructiveHint`/`readOnlyHint` classification already calls
	 * write/destructive. Written as `||` with `isWriteOrDestructive()` FIRST so
	 * that the pre-change verdict is structurally impossible to lose — every
	 * tool gated before this change is still gated after it, whatever its reach.
	 *
	 * What the second clause actually adds, in today's catalogue, is
	 * `hermiq.webSearch` and `hermiq.webFetch`: both declare `scope: read` and
	 * `readOnlyHint: true`, so both pass default-deny and land in any wildcard
	 * grant — while the query, or a model-chosen URL, leaves the instance. Those
	 * two now need naming explicitly, which is the point of the axis.
	 *
	 * @param string $id A tool id (the `mcpId`/dotted form).
	 * @param array<string,mixed>|null $descriptor The catalog descriptor for `$id`, when
	 *                                             available. Null falls through to the
	 *                                             fail-closed rules on BOTH axes.
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `ToolReachResolver` is a pure classification
	 *   function, for the same reason this method is static — see `isWriteOrDestructive()`.
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-default-deny-and-the-approval-gate-key-off-reach-in-union-with-the-existing-rule
	 */
	public static function requiresGrant(string $id, ?array $descriptor = null): bool {
		if (self::isWriteOrDestructive(id: $id, descriptor: $descriptor) === true) {
			return true;
		}

		return ToolReachResolver::atLeast(
			reach: ToolReachResolver::resolve(toolId: $id, descriptor: $descriptor),
			threshold: ToolReachResolver::REACH_INSTANCE
		);

	}//end requiresGrant()

	/**
	 * Classify a descriptor from its declared hint keys only — `scope` first
	 * (closed vocabulary), then `destructiveHint`, then `readOnlyHint`; the
	 * first key the descriptor actually sets wins.
	 *
	 * @param array<string,mixed> $descriptor The catalog descriptor.
	 *
	 * @return bool|null `true`/`false` when a hint key is present and usable,
	 *                   `null` when the descriptor sets none of them (caller
	 *                   falls back to the verb-suffix/fail-closed rules).
	 */
	private static function classifyFromHints(array $descriptor): ?bool {
		if (isset($descriptor['scope']) === true && is_string($descriptor['scope']) === true) {
			return in_array($descriptor['scope'], self::WRITE_VERBS, true);
		}

		if (array_key_exists('destructiveHint', $descriptor) === true && is_bool($descriptor['destructiveHint']) === true) {
			return ($descriptor['destructiveHint'] === true);
		}

		if (array_key_exists('readOnlyHint', $descriptor) === true && is_bool($descriptor['readOnlyHint']) === true) {
			return ($descriptor['readOnlyHint'] === false);
		}

		return null;
	}//end classifyFromHints()

	/**
	 * Expand one grant entry into zero or more concrete catalog ids.
	 *
	 * An argument-scoped grant contributes its BASE id only — the constraints
	 * narrow the ARGUMENTS of that same catalog entry and are enforced at
	 * invocation, so resolution must not invent a second entry for the narrowed
	 * form. A constrained WILDCARD is refused (resolves to nothing): the
	 * constraints would have to apply to ids only the catalog knows, and silently
	 * granting the wildcard unconstrained would widen exactly what the author was
	 * trying to narrow.
	 *
	 * @param string $grant The grant entry.
	 * @param array<int, string> $catalogIds Every id the catalog currently exposes.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-an-argument-scoped-grant-resolves-to-the-underlying-tool
	 */
	private function expandGrant(string $grant, array $catalogIds): array {
		[$grant, $query] = $this->splitGrant(grant: $grant);

		if ($query !== null && $this->isWildcardGrant(grant: $grant) === true) {
			return [];
		}

		if (preg_match('/^(.+)\.\*:write$/', $grant, $matches) === 1) {
			return $this->schemaVerbIds(
				prefix: $matches[1],
				verbs: array_merge(self::READ_VERBS, self::WRITE_VERBS),
				catalogIds: $catalogIds
			);
		}

		if (preg_match('/^(.+)\.\*$/', $grant, $matches) === 1) {
			return $this->schemaVerbIds(prefix: $matches[1], verbs: self::READ_VERBS, catalogIds: $catalogIds);
		}

		// Exact id (a verb-subset `{app}.{schema}.{verb}`, a plain `{app}.{tool}`,
		// or any other exact string) — pass through verbatim, whatever it names.
		// Default-deny does not apply to an explicitly-named grant.
		return [$grant];
	}//end expandGrant()

	/**
	 * Build `{prefix}.{verb}` candidates that actually exist in the catalog.
	 *
	 * @param string $prefix The `{app}.{schema}` prefix.
	 * @param array<int, string> $verbs Candidate verbs to try.
	 * @param array<int, string> $catalogIds Every id the catalog currently exposes.
	 *
	 * @return array<int, string>
	 */
	private function schemaVerbIds(string $prefix, array $verbs, array $catalogIds): array {
		$catalogSet = array_flip($catalogIds);

		$ids = [];
		foreach ($verbs as $verb) {
			$candidate = $prefix . '.' . $verb;
			if (isset($catalogSet[$candidate]) === true) {
				$ids[] = $candidate;
			}
		}

		return $ids;
	}//end schemaVerbIds()

	/**
	 * Whether a grant string uses the `{app}.{schema}.*` (optionally `:write`) form.
	 *
	 * @param string $grant The grant entry.
	 *
	 * @return bool
	 */
	private function isWildcardGrant(string $grant): bool {
		return (str_ends_with($grant, '.*') === true || str_ends_with($grant, '.*:write') === true);
	}//end isWildcardGrant()

	/**
	 * Index every descriptor by its whitelist-matchable id: the dotted `mcpId`
	 * when present (MCP-bridged/derived tools), else the bare `name` — so a grant
	 * is classified from its OWN descriptor's hints (hermiq-prefer-tool-hints),
	 * not from the id text alone.
	 *
	 * @param array<int, mixed> $catalog Descriptor list. Typed loosely on purpose: these cross
	 *                                   the OpenRegister tool-facade boundary, so each entry is
	 *                                   re-checked below.
	 *
	 * @return array<string, array<string, mixed>> id => descriptor, first occurrence wins.
	 */
	private function descriptorsById(array $catalog): array {
		$byId = [];
		foreach ($catalog as $descriptor) {
			if (is_array($descriptor) === false) {
				continue;
			}

			// ⚠️ INDEX BOTH FORMS. A tool has two names and an operator is shown
			// the wrong one.
			//
			// `McpProviderBridge` emits `mcpId` as the dotted raw id
			// (`pipelinq.lead.get`) and `name` as an underscored alias
			// (`pipelinq_lead_get`), because some models reject dots in a
			// function name. `ToolRegistryFacade::functionIsWhitelisted()`
			// already accepts EITHER — but this resolver preferred `mcpId` and
			// indexed only that, so a grant written in the underscored form
			// matched nothing.
			//
			// That is not a hypothetical: the agent editor's own endpoint
			// (`AgentsController::tools()` → hermiq's ToolRegistry) returns the
			// UNDERSCORED name, so an operator copying an id from the UI writes a
			// grant that resolves to zero tools. Measured 2026-08-17: an agent
			// granted five underscored ids reported it had no tools whatsoever,
			// and the failure is silent — `resolvesToNothing()` fires only when
			// EVERY grant misses, so a half-underscored list degrades quietly.
			//
			// Indexing both keys the resolver to the same id space the facade
			// enforces, which is the only way the two cannot drift.
			foreach ([($descriptor['mcpId'] ?? null), ($descriptor['name'] ?? null)] as $id) {
				if (is_string($id) === true && $id !== '' && isset($byId[$id]) === false) {
					$byId[$id] = $descriptor;
				}
			}
		}

		return $byId;
	}//end descriptorsById()

	/**
	 * Drop non-string / empty grant entries.
	 *
	 * @param array<int, mixed> $grants Raw `Agent.tools` entries.
	 *
	 * @return array<int, string>
	 */
	private function sanitizeGrants(array $grants): array {
		// 🔑 The ONE place a stored grant value enters this class, and therefore
		// the one place the STRUCTURED shape has to be understood.
		//
		// `Agent.tools` is now app => subject => action => tool id (see
		// {@see ToolGrantSet}); the legacy `string[]` still loads and still means
		// exactly what it meant. Both are normalised to the grant grammar below,
		// so every resolution rule — wildcard expansion, `:write`, default-deny,
		// argument constraints — and every test covering them is untouched.
		//
		// Converting here rather than teaching this class a second input shape is
		// deliberate: this is the most security-sensitive code in the app, and
		// changing where grants are STORED should not mean rewriting how they are
		// RESOLVED in the same change.
		//
		// ⚠️ A LEGACY LIST IS NOT ROUND-TRIPPED. It is passed through exactly as
		// it arrived, because converting it to the structure and back REORDERS
		// it: the structure groups by app, and a list interleaving two apps comes
		// back grouped. `baseToolIds()` returns grants in order and its
		// compatibility test asserts that order, so the round trip broke a
		// promise the structure had no reason to touch. Only a value that is
		// ALREADY structured goes through the set.
		if (array_is_list($grants) === false) {
			return ToolGrantSet::fromStored(stored: $grants)->toGrantStrings();
		}

		$clean = [];
		foreach ($grants as $grant) {
			if (is_string($grant) === true && $grant !== '') {
				$clean[] = $grant;
			}
		}

		return $clean;
	}//end sanitizeGrants()
}//end class
