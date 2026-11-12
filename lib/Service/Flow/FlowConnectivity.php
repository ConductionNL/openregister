<?php

/**
 * Finds the nodes a run would arrive at and never leave.
 *
 * WHY THIS IS ITS OWN CLASS
 * -------------------------
 * It answers a question about the SHAPE of the graph — which nodes have an exit
 * — while the rest of `FlowNodePreflight` answers questions about each node's
 * TYPE and CONFIG. Those are different subjects over the same document, and
 * keeping them apart is what stops the preflight growing into the place every
 * flow check eventually lands.
 *
 * WHAT IT IS LOOKING FOR
 * ----------------------
 * After `or-flow-action-nodes` a node with no outgoing edge is a dead end: its
 * token arrives, the step runs, the engine finds no enabled transition, and the
 * run stops there — recorded COMPLETED, because from the engine's point of view
 * nothing failed. The author sees a green run that did not do the work.
 *
 * A node escapes that three ways, and only the first two are "ending on
 * purpose" ({@see IFlowEndNode}), OR-ed and never AND-ed:
 *
 *   - the node says `exit: true`
 *   - its TYPE is registered terminal
 *   - it has no type at all — deliberately NOT reported here, because
 *     `FlowDefinitionBuilder` already refuses such a document by name. Two
 *     findings on one node for one defect is how a warning list becomes noise.
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
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The graph-shape half of the flow preflight.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class FlowConnectivity {

	/**
	 * The dead-end warning, given the node id.
	 *
	 * @var string
	 */
	private const DEAD_END_DETAIL = 'Node "%s" has no outgoing edge and does not end the flow, so its token would '
		. 'arrive, run, and stop with the run reported as completed. Connect it, give it '
		. 'a terminal step type, or mark it "exit": true if stopping there is deliberate.';

	/**
	 * The warning for a flow with no trigger node.
	 *
	 * @var string
	 */
	private const NO_TRIGGER_DETAIL = 'This flow has no trigger node, so nothing can ever start it. '
		. 'Add a trigger — an object change, a schedule, or someone running it by hand.';

	/**
	 * The warning for a flow with no end node.
	 *
	 * @var string
	 */
	private const NO_END_DETAIL = 'This flow has no end node, so no path finishes deliberately. '
		. 'Add an End node — it may end in success or in error, but the flow must say where it stops.';

	/**
	 * What to report when a role is missing, trigger first.
	 *
	 * @var array<string, array{reason: string, detail: string}>
	 */
	private const MISSING_ROLE = [
		'trigger' => ['reason' => FlowNodePreflight::REASON_NO_TRIGGER, 'detail' => self::NO_TRIGGER_DETAIL],
		'end' => ['reason' => FlowNodePreflight::REASON_NO_END, 'detail' => self::NO_END_DETAIL],
	];

	/**
	 * Reads edge endpoints the one way the document defines them.
	 *
	 * @var FlowGraph
	 */
	private FlowGraph $graph;

	/**
	 * Constructor.
	 *
	 * @param FlowNodeCatalogueInterface $registry Resolves whether a type ends a path.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function __construct(
		private readonly FlowNodeCatalogueInterface $registry,
	) {
		$this->graph = new FlowGraph();

	}//end __construct()

	/**
	 * One warning per node whose token would have nowhere to go.
	 *
	 * @param array $flow The flow document.
	 *
	 * @return array<int, array<string, string>> The findings.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function deadEnds(array $flow): array {
		$nodes = $this->nodesOf(flow: $flow);
		if ($nodes === []) {
			return [];
		}

		$hasOutgoing = $this->nodeIdsWithAnExit(flow: $flow);
		$deadEnds = array_filter($nodes, fn (mixed $node): bool => $this->isDeadEnd(node: $node, hasOutgoing: $hasOutgoing));

		return array_values(array_map(fn (array $node): array => $this->deadEndFinding(node: $node), $deadEnds));
	}//end deadEnds()

	/**
	 * The warning for one dead-end node.
	 *
	 * @param array $node The node entry, known to be a dead end.
	 *
	 * @return array<string, string> The finding.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function deadEndFinding(array $node): array {
		$id = trim((string)$node['id']);
		$type = $this->typeOf(node: $node);

		return [
			'type' => $type,
			'app' => $this->ownerOf(type: $type),
			'step' => $id,
			'reason' => FlowNodePreflight::REASON_DEAD_END,
			'detail' => sprintf(self::DEAD_END_DETAIL, $id),
		];
	}//end deadEndFinding()

	/**
	 * Whether the flow has a way IN and a way OUT.
	 *
	 * A flow needs at least one TRIGGER node and at least one END node. Without
	 * a trigger nothing can ever start it, and the flow sits there looking
	 * authored while never running — the quietest possible failure. Without an
	 * end node no path finishes deliberately, so every path stops somewhere the
	 * author did not mark and the run is still reported completed.
	 *
	 * Both are reported by TYPE, never by graph position. "The node nothing
	 * points at" and "the node with no outgoing edge" are facts about one
	 * drawing; whether a node can start or end a run is a fact about what it
	 * IS. Reading the topology instead would call an ordinary step a trigger
	 * merely because the author had not connected it yet.
	 *
	 * An END node counts however it finishes: `openregister.end` carries an
	 * `error` flag, and a flow that ends in failure has still ended
	 * deliberately.
	 *
	 * WARNINGS, never blocking — same reasoning as {@see deadEnds()}: a flow
	 * mid-authoring is legitimately missing both. An empty document is not a
	 * flow missing its ends; it is a flow with nothing in it, which the author
	 * can see perfectly well, so it gets no findings.
	 *
	 * @param array $flow The flow document.
	 *
	 * @return array<int, array<string, mixed>> Findings, empty when both are present.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-must-have-a-trigger-and-an-end
	 */
	public function entryAndExit(array $flow): array {
		$nodes = $this->nodesOf(flow: $flow);
		if ($nodes === []) {
			return [];
		}

		return $this->missingRoleFindings(roles: $this->rolesPresent(nodes: $nodes));
	}//end entryAndExit()

	/**
	 * One finding per role the document lacks, trigger first.
	 *
	 * @param array{trigger: bool, end: bool} $roles Which roles are present.
	 *
	 * @return array<int, array<string, string>> The findings.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-must-have-a-trigger-and-an-end
	 */
	private function missingRoleFindings(array $roles): array {
		$findings = [];
		foreach (self::MISSING_ROLE as $role => $missing) {
			if ($roles[$role] === false) {
				$findings[] = $this->flowFinding(reason: $missing['reason'], detail: $missing['detail']);
			}
		}

		return $findings;
	}//end missingRoleFindings()

	/**
	 * A finding about the document as a whole, naming no node.
	 *
	 * @param string $reason The reason constant.
	 * @param string $detail The message.
	 *
	 * @return array<string, string> The finding.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-must-have-a-trigger-and-an-end
	 */
	private function flowFinding(string $reason, string $detail): array {
		return [
			'type' => '',
			'app' => '',
			'step' => '',
			'reason' => $reason,
			'detail' => $detail,
		];
	}//end flowFinding()

	/**
	 * The document's node list, or an empty list when it carries none.
	 *
	 * @param array $flow The flow document.
	 *
	 * @return array<int|string, mixed> The nodes.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function nodesOf(array $flow): array {
		$nodes = ($flow['nodes'] ?? []);
		if (is_array($nodes) === false) {
			return [];
		}

		return $nodes;
	}//end nodesOf()

	/**
	 * Which of the two ROLES the document actually contains.
	 *
	 * The split from `entryAndExit()` is along a real seam: this method
	 * answers "what is in the document", and its caller answers "what should
	 * the author be told".
	 *
	 * `exit: true` is deliberately NOT consulted. That flag marks a node as
	 * ending this PATH, which is what the dead-end check honours; it does not
	 * make the FLOW's exit deliberate. It is a per-instance escape for migrated
	 * documents, not a node that says "the flow finishes here", so a flow whose
	 * only exit is a flag still has no end node and is told so.
	 *
	 * @param array<int|string, mixed> $nodes The document's nodes.
	 *
	 * @return array{trigger: bool, end: bool} Which roles are present.
	 */
	private function rolesPresent(array $nodes): array {
		$roles = ['trigger' => false, 'end' => false];
		foreach ($nodes as $node) {
			$type = $this->typeOf(node: $node);
			if ($type !== '') {
				$roles = $this->withRolesOf(type: $type, roles: $roles);
			}
		}

		return $roles;

	}//end rolesPresent()

	/**
	 * The roles found so far, plus whichever ones this type plays.
	 *
	 * @param string                          $type  The node type.
	 * @param array{trigger: bool, end: bool} $roles The roles found so far.
	 *
	 * @return array{trigger: bool, end: bool} The roles found.
	 */
	private function withRolesOf(string $type, array $roles): array {
		if ($this->registry->isTrigger(type: $type) === true) {
			$roles['trigger'] = true;
		}

		if ($this->registry->isEnd(type: $type) === true) {
			$roles['end'] = true;
		}

		return $roles;
	}//end withRolesOf()

	/**
	 * A node entry's trimmed type, or an empty string when it is not a record.
	 *
	 * @param mixed $node The node entry.
	 *
	 * @return string The type.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function typeOf(mixed $node): string {
		if (is_array($node) === false) {
			return '';
		}

		return trim((string)($node['type'] ?? ''));
	}//end typeOf()

	/**
	 * Every node id that some edge leaves from.
	 *
	 * `from` is the only key that matters: a node with an outgoing edge has
	 * somewhere to send its token, however many edges arrive at it.
	 *
	 * ⚠️ `from` IS A LIST, AND READING IT AS A SCALAR REPORTED EVERY FLOW AS
	 * BROKEN. An endpoint fans out — `{"from": ["a"], "to": ["b"]}` is the shape
	 * `FlowDefinitionBuilder` writes and `FlowGraph::outgoing()` reads. This
	 * method used `(string)$edge['from']`, and casting `["a"]` to string yields
	 * the literal `"Array"`: the set came back keyed by `"Array"`, no real node
	 * id was ever in it, and so EVERY non-terminal node was reported as a dead
	 * end. The flow was drawn correctly, saved correctly and refused to run.
	 *
	 * It failed this way round because a wrong cast is not an error in PHP —
	 * it produces a plausible string and carries on. The normaliser is the
	 * document's only definition of an endpoint, so this reads through it.
	 *
	 * @param array $flow The flow document.
	 *
	 * @return array<string, boolean> A set keyed by node id.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function nodeIdsWithAnExit(array $flow): array {
		$hasOutgoing = [];
		foreach (($flow['edges'] ?? []) as $edge) {
			if (is_array($edge) === false) {
				continue;
			}

			foreach ($this->graph->normaliseEndpoints(value: ($edge['from'] ?? null)) as $from) {
				$hasOutgoing[$from] = true;
			}
		}//end foreach

		return $hasOutgoing;
	}//end nodeIdsWithAnExit()

	/**
	 * Whether this node's token would arrive and then have nowhere to go.
	 *
	 * @param mixed $node The node entry.
	 * @param array<string, boolean> $hasOutgoing Node ids that have an exit.
	 *
	 * @return boolean True when the node is a dead end.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function isDeadEnd(mixed $node, array $hasOutgoing): bool {
		if ($this->isExempt(node: $node, hasOutgoing: $hasOutgoing) === true) {
			return false;
		}

		$type = $this->typeOf(node: $node);

		return ($type !== '' && $this->registry->isEnd(type: $type) === false);
	}//end isDeadEnd()

	/**
	 * Whether a node entry is out of the dead-end check's reach.
	 *
	 * It is when it is not a record, carries no id, has an outgoing edge, or
	 * says `exit: true`.
	 *
	 * @param mixed $node The node entry.
	 * @param array<string, boolean> $hasOutgoing Node ids that have an exit.
	 *
	 * @return boolean True when the node cannot be a dead end.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function isExempt(mixed $node, array $hasOutgoing): bool {
		if (is_array($node) === false) {
			return true;
		}

		$id = trim((string)($node['id'] ?? ''));

		return ($id === '' || isset($hasOutgoing[$id]) === true || ($node['exit'] ?? false) === true);
	}//end isExempt()

	/**
	 * The app id a namespaced node type belongs to.
	 *
	 * @param string $type The node type id.
	 *
	 * @return string The owning app id, or an empty string.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function ownerOf(string $type): string {
		$separator = strpos($type, '.');
		if ($separator === false || $separator === 0) {
			return '';
		}

		return substr($type, 0, $separator);
	}//end ownerOf()
}//end class
