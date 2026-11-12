<?php

/**
 * Checks a flow document's step types against the live node registry.
 *
 * `FlowNodeRegistry::get()` already refuses an unknown type, and refuses it for
 * the right reason — a silently skipped step produces a run that reports success
 * while never having done the work. The defect this class closes is *when* it
 * refuses: at step-dispatch time, mid-run, after earlier steps have already
 * written to the outside world. Labels have moved, commits have landed, issues
 * have been filed, and none of it rolls back when step four turns out to name a
 * node this instance does not have.
 *
 * The measured case: `openregister.explode` shipped in or#2247 and was named by
 * a flow document while the running instance sat at or#2244. Nothing noticed
 * until someone checked by hand. The flow saved cleanly, ran, did real work, and
 * then died in the middle.
 *
 * So the check moves to save/import time, where refusing costs nothing.
 *
 * ## Why not simply refuse every unknown type
 *
 * Because a flow legitimately outlives the apps on one instance. A shared
 * configuration export carries flows naming `openconnector.source-call` and
 * `hermiq.agent-step`; importing it onto an instance that has not installed
 * openconnector yet must not fail — the flow is not wrong, the instance is
 * merely incomplete, and it will become correct the moment the app is enabled.
 * The fleet treats optional apps that way everywhere else (see the `IAppManager`
 * probes in `ExternalIntegrationRouter`, `TalkLinkService`, `AnalyticsProvider`).
 *
 * The distinction that makes a hard refusal safe is ownership. Node ids are
 * namespaced with the owning app by contract ({@see IFlowNode::getId()}), so the
 * owner of `openregister.explode` is `openregister`. That gives two genuinely
 * different situations:
 *
 * - The owning app **is enabled** and still does not provide the type. No
 *   install can fix this: the app is right here and does not have it. It is
 *   version drift or a typo, always a defect, never a legitimate absence. This
 *   is the or#2247 case, and it is REFUSED.
 * - The owning app **is not enabled**. Installing it is exactly the fix, and the
 *   document is not wrong. WARNED, loudly and structurally, and saved.
 *
 * ## The type resolving is only half the question
 *
 * A resolved type says the app provides the step. It says nothing about whether
 * the node can READ the config the edge hands it, and that second half produced
 * its own set of measured failures (hydra#489, four inert flows). The check has
 * two parts, and it needs both:
 *
 * - `validateConfig()` — the node's own opinion. Catches a REQUIRED key that is
 *   missing or malformed. It cannot catch anything else, because a node only
 *   examines the keys it looks for; a key it does not look for is invisible to
 *   it by construction. Where the required set is empty the method is a no-op no
 *   matter how carefully it is written — `EndNode::validateConfig()` is empty
 *   for exactly that reason, and it is not wrong to be.
 * - The node's declared vocabulary ({@see IFlowNodeConfigKeys}) — catches a key
 *   the node will silently ignore. `EndNode` accepting `status`/`reason` while
 *   reading `error`/`message`, `SubFlowNode` accepting `input`/`output` while
 *   implementing neither: both satisfied every required key and both were inert.
 *
 * Keys beginning with `$` are authoring annotations (`$why`, `$comment`), used
 * throughout the fleet's flow documents and read by nothing. They are exempt by
 * contract — a check that refused them would refuse nearly every real flow,
 * which is worse than no check.
 *
 * A node that does not declare a vocabulary is not vocabulary-checked. That is
 * today's behaviour preserved for out-of-tree nodes, not an oversight; see
 * {@see IFlowNodeConfigKeys} for why the contract is opt-in.
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
 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Resolves every step type in a flow document before the flow can run.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The complexity is a count of
 *   the distinct ways a flow document can be wrong, spread across ten short
 *   single-purpose methods (the longest branches four ways). Splitting it would
 *   move the branching, not remove it, and would put "what makes a step
 *   unrunnable" in two places — which is the drift this class exists to end.
 *
 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
 */
class FlowNodePreflight implements FlowNodePreflightInterface {

	/**
	 * The owning app is enabled but does not provide the type.
	 *
	 * Unfixable by installing anything — a defect, and therefore blocking.
	 */
	public const REASON_MISSING_FROM_OWNER = 'node-type-missing-from-enabled-app';

	/**
	 * The type carries no `app.` namespace, so no app can ever own it.
	 *
	 * Blocking: there is no install that would make this resolve.
	 */
	public const REASON_NOT_NAMESPACED = 'node-type-not-namespaced';

	/**
	 * The owning app is not enabled on this instance.
	 *
	 * Installing it is the fix, so this warns rather than blocks.
	 */
	public const REASON_OWNER_NOT_ENABLED = 'node-type-owner-app-not-enabled';

	/**
	 * The type resolves, but the node refuses the config it was given.
	 *
	 * Blocking. A step whose config the node cannot read is not a step that
	 * half-works — it is a step that returns its input untouched and reports
	 * COMPLETED, which is the failure this whole class exists to remove.
	 */
	public const REASON_CONFIG_REJECTED = 'node-config-rejected';

	/**
	 * The config carries a key the node never reads.
	 *
	 * Blocking, and it is the half `validateConfig()` structurally cannot see.
	 * That method answers "is anything REQUIRED missing"; a step written in
	 * another node's dialect can satisfy every required key and still be inert,
	 * because the keys carrying the actual intent are ones the node ignores.
	 * `EndNode` requires nothing at all, so it accepted `status`/`reason`
	 * (it reads `error`/`message`) and stopped runs with the generic "Flow
	 * stopped" and `isError=false`. `SubFlowNode` requires only `flow`, so it
	 * accepted `input`/`output` and passed the child nothing. Neither was
	 * catchable until a node could name its whole vocabulary.
	 */
	public const REASON_CONFIG_UNKNOWN_KEY = 'node-config-unknown-key';

	/**
	 * `onError` was put in `config` instead of on the edge.
	 *
	 * `FlowEngine::policyFor()` reads `$step['onError']` — the EDGE, one level
	 * up from `config`. A policy inside `config` is read by nothing.
	 *
	 * Blocking only when the buried policy differs from the engine default
	 * (`stop`), because that is exactly when it changes what the run does: the
	 * author asked for `continue` or `dead_letter` and silently got `stop`.
	 * A buried `"stop"` is still wrong, but it is wrong in the direction the
	 * engine was already going, so it warns instead of refusing a fleet's worth
	 * of live flow documents over a no-op key. Same rule, same threshold, as
	 * `hydra/scripts/test-flow-definitions.sh` — deliberately, so the two
	 * guards cannot disagree about the same document.
	 */
	public const REASON_CONFIG_ONERROR_MISPLACED = 'node-config-onerror-misplaced';

	/**
	 * The document is in the pre-inversion shape: a step is on an EDGE.
	 *
	 * @var string
	 */
	public const REASON_PRE_INVERSION_SHAPE = 'flow-pre-inversion-shape';

	/**
	 * A node that is not terminal, and has nowhere to send its token.
	 *
	 * A WARNING, never blocking. A half-wired flow is the normal state of one
	 * being authored, and refusing to SAVE it would make the editor unusable —
	 * the author would have to build the graph in an order that is never
	 * disconnected, which no editor can require. The refusal belongs at RUN
	 * time, where the cost of a silent stop is actually paid.
	 */
	public const REASON_DEAD_END = 'node-dead-end';

	/**
	 * The flow carries no TRIGGER node, so nothing can ever start it.
	 *
	 * A WARNING for the same reason as a dead end: a flow being authored is
	 * incomplete by definition, and refusing the save would force the author to
	 * build in an order where the document is never invalid — which no editor
	 * can require. The editor surfaces it as an error banner, and the cost is
	 * paid at run time, not at save time.
	 */
	public const REASON_NO_TRIGGER = 'flow-has-no-trigger';

	/**
	 * The flow carries no END node, so no path finishes deliberately.
	 *
	 * Distinct from {@see REASON_DEAD_END}, which is about ONE node having
	 * nowhere to send its token. This is about the document as a whole: a flow
	 * with no end node has no deliberate exit at all, and every path through it
	 * stops somewhere the author did not mark.
	 */
	public const REASON_NO_END = 'flow-has-no-end';

	/**
	 * The `onError` policy the engine applies when an edge declares none.
	 *
	 * Defined once, beside the check that compares against it.
	 */
	private const DEFAULT_ONERROR = FlowPreflightStep::DEFAULT_ONERROR;

	/**
	 * The refusal line per blocking reason. Arguments: 1 type, 2 step,
	 * 3 detail (default "?"), 4 what the node reads, 5 the default onError,
	 * 6 detail (default "no detail"), 7 the owning app.
	 *
	 * @var array<string, string>
	 */
	private const FINDING_LINES = [
		self::REASON_CONFIG_UNKNOWN_KEY => '"%1$s" (step %2$s) — config key(s) %3$s, which that node never reads; '
			. 'it reads %4$s. Ignored keys make the step a pass-through that reports success',
		self::REASON_CONFIG_ONERROR_MISPLACED => '"%1$s" (step %2$s) — config.onError="%3$s" is read by nothing; the engine takes the policy '
			. 'from the EDGE, beside "type". As written this step gets "%5$s"',
		self::REASON_CONFIG_REJECTED => '"%1$s" (step %2$s) — the node exists but refuses this step\'s config: %6$s. '
			. 'A config it cannot read makes the step a pass-through that reports success',
		self::REASON_PRE_INVERSION_SHAPE => '"%1$s" (step %2$s) — the step is on an EDGE. A node is the action that runs and an edge is '
			. 'sequence, so nothing reads this step. The flow has not been migrated; run '
			. 'or-flow-migrate-definitions',
		self::REASON_NOT_NAMESPACED => '"%1$s" (step %2$s) — not namespaced, so no app can provide it; '
			. 'step types are "<app>.<node>", e.g. "openregister.route"',
	];

	/**
	 * The refusal line for a type its owning app does not provide.
	 *
	 * @var string
	 */
	private const FINDING_LINE_NOT_PROVIDED = '"%1$s" (step %2$s) — app "%7$s" would own it and IS enabled here, but does not provide it; '
		. 'that app is behind the flow';

	/**
	 * A report with nothing in it.
	 *
	 * @var array{blocking: array, warnings: array}
	 */
	private const EMPTY_REPORT = [
		'blocking' => [],
		'warnings' => [],
	];

	/**
	 * The refusal message: flow name, finding count, the joined finding lines.
	 *
	 * @var string
	 */
	private const REFUSAL_MESSAGE = 'Flow "%s" has %d step(s) this instance cannot run as written: %s. '
		. 'Refusing the save: a step that cannot run only fails once the flow is already halfway through, '
		. 'after earlier steps have made changes that do not roll back — and a step whose config is ignored '
		. 'does not fail at all, it reports success having done nothing.';

	/**
	 * The log line for an `onError` written one level too deep: flow, step, type, value.
	 *
	 * @var string
	 */
	private const LOG_ONERROR_MISPLACED = '[FlowNodePreflight] Flow "%s", step "%s" (%s) sets config.onError="%s". '
		. 'Nothing reads that: the engine takes the policy from the NODE, beside "type". '
		. 'It happens to match the default, so the run behaves the same — move it up one level.';

	/**
	 * The log line for every other warning: flow, type, app.
	 *
	 * Loud on purpose. The measured failure was not that a step was missing,
	 * it was that nothing said so — the run reported success.
	 *
	 * @var string
	 */
	private const LOG_OWNER_NOT_ENABLED = '[FlowNodePreflight] Flow "%s" uses step type "%s", which app "%s" owns. '
		. 'That app is not enabled here, so this flow cannot run until it is.';

	/**
	 * Constructor.
	 *
	 * @param FlowNodeCatalogueInterface $registry The live catalogue of node types.
	 * @param IAppManager $appManager Tells an absent app from a stale one.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly FlowNodeCatalogueInterface $registry,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether a piece of object data is shaped like a flow document.
	 *
	 * Deliberately structural rather than register/schema based: flows live in
	 * hermiq's `agentflow` objects, in OpenRegister's own `flow` schema, and in
	 * whatever a future app nominates. Keying off the shape means the check
	 * follows the documents instead of a configured location that can drift.
	 *
	 * The signature is a graph: a list of nodes carrying ids, and a list of
	 * edges carrying endpoints. Anything looser would start judging objects
	 * that merely happen to own a `nodes` key.
	 *
	 * @param array $data The object's data.
	 *
	 * @return boolean True when the data describes a flow graph.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function looksLikeFlow(array $data): bool {
		$nodes = ($data['nodes'] ?? null);
		$edges = ($data['edges'] ?? null);
		if (is_array($nodes) === false || is_array($edges) === false) {
			return false;
		}

		return ($this->nodesLookRight(nodes: $nodes) === true && $this->edgesLookRight(edges: $edges) === true);
	}//end looksLikeFlow()

	/**
	 * Whether a `nodes` list is non-empty and every entry a record carrying an id.
	 *
	 * @param array $nodes The candidate node list.
	 *
	 * @return boolean True when it is.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function nodesLookRight(array $nodes): bool {
		if ($nodes === []) {
			return false;
		}

		foreach ($nodes as $node) {
			if (is_array($node) === false || isset($node['id']) === false) {
				return false;
			}
		}

		return true;
	}//end nodesLookRight()

	/**
	 * Whether an `edges` list is non-empty and every entry carries both endpoints.
	 *
	 * @param array $edges The candidate edge list.
	 *
	 * @return boolean True when it is.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function edgesLookRight(array $edges): bool {
		if ($edges === []) {
			return false;
		}

		foreach ($edges as $edge) {
			if ($this->edgeLooksRight(edge: $edge) === false) {
				return false;
			}
		}

		return true;
	}//end edgesLookRight()

	/**
	 * Whether one `edges` entry is a record carrying both endpoints.
	 *
	 * Either spelling is accepted — `from`/`to` is this engine's, `source`/
	 * `target` is what most graph editors emit.
	 *
	 * @param mixed $edge The candidate edge.
	 *
	 * @return boolean True when it does.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function edgeLooksRight(mixed $edge): bool {
		if (is_array($edge) === false) {
			return false;
		}

		$hasFrom = (isset($edge['from']) === true || isset($edge['source']) === true);
		$hasTo = (isset($edge['to']) === true || isset($edge['target']) === true);

		return ($hasFrom === true && $hasTo === true);
	}//end edgeLooksRight()

	/**
	 * Resolve every step type the document names.
	 *
	 * The graph's *shape* is deliberately not validated here even though
	 * {@see FlowDefinitionBuilder} could: a flow being drawn is legitimately
	 * half-connected, and refusing to save a draft would make the editor
	 * unusable. A step type, by contrast, is never bogus on purpose — nobody
	 * drafts a node type they do not mean.
	 *
	 * A node with no `type` is a pass-through and is skipped, matching
	 * `RegistryStepDispatcher`, which returns items untouched for such a step.
	 *
	 * A resolved type is checked TWICE: once that it exists, and once that the
	 * node accepts the config it is given. The second check is the one that
	 * catches a step written in another node's dialect — the type is right,
	 * the step runs, and it does nothing.
	 *
	 * A document still in the pre-inversion shape returns early with that one
	 * refusal. Every later finding would be about a document in a shape
	 * nothing will read, and burying the one actionable message under them
	 * helps nobody.
	 *
	 * The graph-SHAPE questions — dead ends, and whether the flow has a way in
	 * and a way out at all — live in {@see FlowConnectivity}: this class answers
	 * questions about each node's type and config, a different subject over
	 * the same document.
	 *
	 * @param array $flow The flow document.
	 *
	 * @return array{blocking: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function inspect(array $flow): array {
		$blocking = $this->preInversionFindings(flow: $flow);
		if ($blocking !== []) {
			return ['blocking' => $blocking, 'warnings' => []];
		}

		$report = $this->nodesReport(flow: $flow);
		$connectivity = new FlowConnectivity($this->registry);
		$report['warnings'] = array_merge($report['warnings'], $connectivity->deadEnds(flow: $flow), $connectivity->entryAndExit(flow: $flow));

		return $report;
	}//end inspect()

	/**
	 * The findings over every node of the document.
	 *
	 * NODES, not edges. A node is the action that runs (or-flow-action-nodes),
	 * so a preflight still walking the `edges` list would inspect a list where nothing
	 * carries a `type` any more: every loop body skipped, zero findings, and a
	 * report that says "valid" about a document it never looked at. A
	 * validator that cannot fail is worse than none, because it is believed.
	 *
	 * @param array $flow The flow document.
	 *
	 * @return array{blocking: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function nodesReport(array $flow): array {
		$report = self::EMPTY_REPORT;
		foreach (($flow['nodes'] ?? []) as $index => $entry) {
			$report = $this->mergeReports(first: $report, second: $this->nodeFindings(entry: $entry, index: $index));
		}

		return $report;
	}//end nodesReport()

	/**
	 * Two reports as one, the first's findings ahead of the second's.
	 *
	 * @param array{blocking: array, warnings: array} $first  The earlier report.
	 * @param array{blocking: array, warnings: array} $second The later report.
	 *
	 * @return array{blocking: array, warnings: array} The combined report.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function mergeReports(array $first, array $second): array {
		return [
			'blocking' => array_merge($first['blocking'], $second['blocking']),
			'warnings' => array_merge($first['warnings'], $second['warnings']),
		];
	}//end mergeReports()

	/**
	 * Refuse a document whose steps still sit on edges.
	 *
	 * Walking nodes made this a hole rather than closing one: an un-migrated
	 * flow carries its steps on EDGES, so the node loop finds no typed nodes,
	 * produces no findings, and the report says "valid" — about the one
	 * document shape the engine will certainly refuse. Measured on the live
	 * Hydra sequencer, which validated clean while being unrunnable.
	 *
	 * The author reading "the flow engine accepts this graph" in the editor is
	 * exactly who needs to be told otherwise.
	 *
	 * @param array $flow The flow document.
	 *
	 * @return array<int, array<string, string>> Blocking findings, empty when the shape is current.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function preInversionFindings(array $flow): array {
		$blocking = [];
		foreach (($flow['edges'] ?? []) as $index => $edge) {
			$step = $this->stepOf(entry: $edge, index: $index);
			if ($step !== null) {
				$blocking[] = $step->finding(
					reason: self::REASON_PRE_INVERSION_SHAPE,
					detail: 'the step is on an edge; a node is the action that runs'
				);
			}
		}

		return $blocking;
	}//end preInversionFindings()

	/**
	 * The typed step a document entry declares, or null when it declares none.
	 *
	 * @param mixed      $entry The raw node or edge entry from the document.
	 * @param int|string $index Its position, used when it carries no id or name.
	 *
	 * @return FlowPreflightStep|null The step.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function stepOf(mixed $entry, int|string $index): ?FlowPreflightStep {
		if (is_array($entry) === false) {
			return null;
		}

		$type = trim((string)($entry['type'] ?? ''));
		if ($type === '') {
			return null;
		}

		return new FlowPreflightStep(entry: $entry, type: $type, stepId: (string)($entry['id'] ?? $entry['name'] ?? $index));
	}//end stepOf()

	/**
	 * Inspect one node entry.
	 *
	 * @param mixed $entry The raw node entry from the document.
	 * @param int|string $index Its position, used when it carries no id or name.
	 *
	 * @return array{blocking: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function nodeFindings(mixed $entry, int|string $index): array {
		$step = $this->stepOf(entry: $entry, index: $index);
		if ($step === null) {
			return self::EMPTY_REPORT;
		}

		$node = $this->resolve(type: $step->type);
		if ($node === null) {
			return $this->unresolvedFindings(step: $step);
		}

		return $this->resolvedFindings(node: $node, step: $step);
	}//end nodeFindings()

	/**
	 * The findings about a step whose type resolves.
	 *
	 * The type resolving is only half the question. A node reads the config
	 * keys IT implements, and silently ignores the rest — so a step written in
	 * another node's dialect resolves fine, runs, and returns its input
	 * untouched while reporting COMPLETED.
	 *
	 * Measured across the ten hydra flows: four are inert this way.
	 * `hydra-analyze-verdicts` declares `routes.*.when`/`routes.*.to` where
	 * RouterNode reads `rules.*.output`, and `config.fields` where SetFieldsNode
	 * reads `set`/`compute`. The flow cannot run at all, and
	 * `scripts/test-flow-definitions.sh` passes on it — that script checks graph
	 * STRUCTURE and is blind to dialect.
	 *
	 * Every node already implements `validateConfig()`; the contract is on
	 * IFlowNode. It was simply never called at save time. SetFieldsNode's own
	 * docblock says the check exists so a malformed expression is "caught
	 * HERE, when the flow is saved, rather than evaluating to null on every
	 * item at 03:00" — which is exactly what it did NOT do, because nothing
	 * called it.
	 *
	 * ...and the half a required-key check cannot reach. A node only sees the
	 * keys it looks for; the ones it does not look for are invisible to it BY
	 * CONSTRUCTION, however careful its validateConfig() is. Only the node's
	 * declared vocabulary ({@see IFlowNodeConfigKeys}) can expose those.
	 *
	 * @param IFlowNode         $node The resolved node.
	 * @param FlowPreflightStep $step The step.
	 *
	 * @return array{blocking: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function resolvedFindings(IFlowNode $node, FlowPreflightStep $step): array {
		$report = self::EMPTY_REPORT;
		$rejection = $this->configRejection(node: $node, step: $step);
		if ($rejection !== null) {
			$report['blocking'][] = $step->finding(reason: self::REASON_CONFIG_REJECTED, detail: $rejection);
		}

		return $this->mergeReports(first: $report, second: $this->dialectFindings(node: $node, step: $step));
	}//end resolvedFindings()

	/**
	 * Why a step's type does not resolve, as a report.
	 *
	 * A type with no `app.` namespace can never be owned, and a type its
	 * enabled owner does not provide is a defect: both block. An owning app
	 * that is not enabled warns — installing it is the fix.
	 *
	 * @param FlowPreflightStep $step The step.
	 *
	 * @return array{blocking: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function unresolvedFindings(FlowPreflightStep $step): array {
		if ($step->app() === '') {
			return ['blocking' => [$step->finding(reason: self::REASON_NOT_NAMESPACED)], 'warnings' => []];
		}

		if ($this->isAppEnabled(appId: $step->app()) === true) {
			return ['blocking' => [$step->finding(reason: self::REASON_MISSING_FROM_OWNER)], 'warnings' => []];
		}

		return ['blocking' => [], 'warnings' => [$step->finding(reason: self::REASON_OWNER_NOT_ENABLED)]];
	}//end unresolvedFindings()

	/**
	 * Refuse a document the instance cannot run, and log the rest.
	 *
	 * @param array $flow The flow document.
	 * @param string|null $label A human name for the flow, used in the message.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When a step type can never resolve here.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function assertRunnable(array $flow, ?string $label = null): void {
		$report = $this->inspect(flow: $flow);
		$name = ($label ?? (string)($flow['name'] ?? 'flow'));

		foreach ($report['warnings'] as $warning) {
			$this->logger->warning(
				message: $this->warningMessage(flow: $name, warning: $warning),
				context: $this->warningContext(flow: $name, warning: $warning)
			);
		}

		if ($report['blocking'] !== []) {
			throw new UnexpectedValueException($this->describe(flow: $name, blocking: $report['blocking']));
		}
	}//end assertRunnable()

	/**
	 * The log line for one warning.
	 *
	 * @param string                $flow    The flow's name.
	 * @param array<string, string> $warning The warning finding.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function warningMessage(string $flow, array $warning): string {
		if ($warning['reason'] === self::REASON_CONFIG_ONERROR_MISPLACED) {
			return sprintf(self::LOG_ONERROR_MISPLACED, $flow, $warning['step'], $warning['type'], ($warning['detail'] ?? ''));
		}

		return sprintf(self::LOG_OWNER_NOT_ENABLED, $flow, $warning['type'], $warning['app']);
	}//end warningMessage()

	/**
	 * The log context for one warning; the `onError` one carries no app.
	 *
	 * @param string                $flow    The flow's name.
	 * @param array<string, string> $warning The warning finding.
	 *
	 * @return array<string, mixed> The context.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function warningContext(string $flow, array $warning): array {
		$context = ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flow, 'type' => $warning['type']];
		if ($warning['reason'] !== self::REASON_CONFIG_ONERROR_MISPLACED) {
			$context['app'] = $warning['app'];
		}

		return ($context + ['step' => $warning['step'], 'reason' => $warning['reason']]);
	}//end warningContext()

	/**
	 * Build the refusal message.
	 *
	 * Names every offending type and the app that owns it, all of them at once.
	 * Reporting only the first would turn one fix into a fix-save-fix loop.
	 *
	 * @param string $flow The flow's name.
	 * @param array<int, array<string, string>> $blocking The blocking findings.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function describe(string $flow, array $blocking): string {
		$lines = array_map(fn (array $finding): string => $this->describeFinding(finding: $finding), $blocking);

		return sprintf(self::REFUSAL_MESSAGE, $flow, count($blocking), implode('; ', $lines));

	}//end describe()

	/**
	 * One finding of the refusal message, in the words of its reason.
	 *
	 * A node with an empty vocabulary is said in words: "it reads " followed
	 * by nothing reads like a truncated message, not a deliberate answer.
	 *
	 * @param array<string, string> $finding The blocking finding.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function describeFinding(array $finding): string {
		return sprintf(
			(self::FINDING_LINES[$finding['reason']] ?? self::FINDING_LINE_NOT_PROVIDED),
			$finding['type'],
			$finding['step'],
			($finding['detail'] ?? '?'),
			$this->readsOf(finding: $finding),
			self::DEFAULT_ONERROR,
			($finding['detail'] ?? 'no detail'),
			($finding['app'] ?? null)
		);
	}//end describeFinding()

	/**
	 * What a finding says the node reads, in words when it reads nothing.
	 *
	 * @param array<string, string> $finding The blocking finding.
	 *
	 * @return string The vocabulary, or "no config at all".
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function readsOf(array $finding): string {
		$reads = (string)($finding['reads'] ?? '');
		if ($reads === '') {
			return 'no config at all';
		}

		return $reads;
	}//end readsOf()

	/**
	 * Ask the node whether it can read this step's config.
	 *
	 * @param IFlowNode         $node The resolved node.
	 * @param FlowPreflightStep $step The step.
	 *
	 * @return string|null The node's message, or null when it accepts the config.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function configRejection(IFlowNode $node, FlowPreflightStep $step): ?string {
		$config = $step->config();
		if (is_array($config) === false) {
			return 'Step config must be an object.';
		}

		return $this->refusalOf(node: $node, config: $config);
	}//end configRejection()

	/**
	 * The node's own refusal of a config, or null when it accepts it.
	 *
	 * A node that throws for a reason of its own (a broken translation, a
	 * missing collaborator) must not block the save — that would turn one bad
	 * node into an unsavable instance. Only the node's own refusal counts.
	 *
	 * BOTH SPL "bad argument" exceptions count as a refusal. This used to catch
	 * `UnexpectedValueException` alone, on the stated grounds that it "is what
	 * every implementation in-tree raises". That was untrue:
	 * `TriggerScheduleNode`, `TriggerObjectNode` and `FlowStateNode` all raise
	 * `InvalidArgumentException`, so their refusals were swallowed into the
	 * warning below and the flow saved clean.
	 *
	 * The consequence was the worst shape a validator can have — silent
	 * acceptance of exactly the configs it exists to catch. Measured against a
	 * live instance: a `trigger-schedule` carrying `cron: "@hourly"` (not a
	 * five-field expression) and a `trigger-object` naming a register that does
	 * not exist BOTH returned `{"valid":true,"blocking":[],"warnings":[]}`. The
	 * first flow would never fire; the second would subscribe to nothing.
	 * Neither failure is visible anywhere until someone wonders why a flow has
	 * not run.
	 *
	 * Discriminating on the SPL subclass was always fragile: both mean "this
	 * argument is wrong", the choice between them is a matter of taste, and
	 * third-party nodes (openconnector, hermiq) pick whichever they like. The
	 * distinction that actually matters — the node REFUSED the config, versus
	 * the node BROKE — is preserved by keeping the `Throwable` arm below.
	 *
	 * @param IFlowNode $node   The resolved node.
	 * @param array     $config The step's configuration.
	 *
	 * @return string|null The node's message, or null when it accepts the config.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function refusalOf(IFlowNode $node, array $config): ?string {
		try {
			$node->validateConfig($config);
		} catch (UnexpectedValueException | InvalidArgumentException $e) {
			return $e->getMessage();
		} catch (Throwable $e) {
			$this->logNodeFailure(
				message: 'A node\'s validateConfig() failed for its own reasons, not blocking: ' . $e->getMessage(),
				node: $node
			);
		}

		return null;
	}//end refusalOf()

	/**
	 * Log a node that broke while being asked about itself.
	 *
	 * @param string    $message What failed, without the class prefix.
	 * @param IFlowNode $node    The node that failed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function logNodeFailure(string $message, IFlowNode $node): void {
		$this->logger->warning(
			message: '[FlowNodePreflight] ' . $message,
			context: ['file' => __FILE__, 'line' => __LINE__, 'node' => get_class($node)]
		);
	}//end logNodeFailure()

	/**
	 * Every config key on this step that the node will never read.
	 *
	 * Only asked of a node that declares its vocabulary. A node that does not
	 * implement {@see IFlowNodeConfigKeys} is skipped entirely and silently:
	 * OpenRegister cannot know what `openconnector.source-call` or
	 * `hermiq.agent-step` read, and guessing would refuse correct flows from
	 * apps that shipped before this contract existed. That is today's behaviour
	 * preserved exactly — no node gets WORSE, some get better.
	 *
	 * Two exemptions, both deliberate and both documented on the interface:
	 *
	 * - A key beginning with `$` is an authoring annotation. `$why` and
	 *   `$comment` appear throughout the fleet's flow documents, explaining to
	 *   the next reader why a step exists. The engine has never read them. A
	 *   check without this exemption would refuse nearly every real flow, which
	 *   is a worse outcome than no check at all.
	 * - `onError` gets its own diagnosis rather than a generic "unknown key",
	 *   because the author did not invent it — they put a real engine option
	 *   one level too deep, and telling them so is worth more than telling them
	 *   the key is unrecognised.
	 *
	 * @param IFlowNode         $node The resolved node.
	 * @param FlowPreflightStep $step The step.
	 *
	 * @return array{blocking: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function dialectFindings(IFlowNode $node, FlowPreflightStep $step): array {
		$config = $step->config();
		$known = $this->declaredKeys(node: $node);
		if ($known === null || is_array($config) === false || $config === []) {
			return self::EMPTY_REPORT;
		}

		return $step->dialectFindings(known: $known);
	}//end dialectFindings()

	/**
	 * A node's declared config vocabulary, or null when it declares none.
	 *
	 * Null and `[]` are different answers and the caller must be able to tell
	 * them apart: `[]` is `openregister.switch` saying it reads no config at
	 * all, null is a node that never joined the contract and must therefore not
	 * be judged by it.
	 *
	 * A node whose own vocabulary lookup throws must not block the save — same
	 * reasoning as refusalOf(): one broken node cannot be allowed to make the
	 * instance unsavable.
	 *
	 * @param IFlowNode $node The resolved node.
	 *
	 * @return array<int, string>|null The keys, or null when unchecked.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function declaredKeys(IFlowNode $node): ?array {
		if (($node instanceof IFlowNodeConfigKeys) === false) {
			return null;
		}

		try {
			$known = $node->configKeys();
		} catch (Throwable $e) {
			$this->logNodeFailure(message: 'A node\'s configKeys() failed, skipping the dialect check: ' . $e->getMessage(), node: $node);
			return null;
		}

		return array_map(static fn ($key): string => (string)$key, (array)$known);
	}//end declaredKeys()

	/**
	 * The node answering to a type, or null when nothing does.
	 *
	 * @param string $type The step type.
	 *
	 * @return IFlowNode|null The node.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function resolve(string $type): ?IFlowNode {
		try {
			return $this->registry->get(type: $type);
		} catch (UnexpectedValueException) {
			return null;
		}

	}//end resolve()

	/**
	 * Whether an app is enabled on this instance.
	 *
	 * A throwing app manager must not be read as "app absent": that would turn
	 * an infrastructure hiccup into a blocked save. Treat it as enabled=false,
	 * which downgrades the finding to a warning rather than a refusal.
	 *
	 * @param string $appId The app id.
	 *
	 * @return boolean True when the app is enabled.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	private function isAppEnabled(string $appId): bool {
		try {
			return $this->appManager->isEnabledForUser($appId);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowNodePreflight] Could not determine whether app "' . $appId . '" is enabled: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'app' => $appId]
			);
			return false;
		}

	}//end isAppEnabled()
}//end class
