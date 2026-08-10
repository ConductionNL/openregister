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
class FlowConnectivity
{
    /**
     * Constructor.
     *
     * @param FlowNodeRegistry $registry Resolves whether a type ends a path.
     *
     * @spec openspec/specs/flow-engine/spec.md
     */
    public function __construct(private readonly FlowNodeRegistry $registry)
    {

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
    public function deadEnds(array $flow): array
    {
        $nodes = ($flow['nodes'] ?? []);
        if (is_array($nodes) === false || $nodes === []) {
            return [];
        }

        $hasOutgoing = $this->nodeIdsWithAnExit(flow: $flow);

        $warnings = [];
        foreach ($nodes as $node) {
            if ($this->isDeadEnd(node: $node, hasOutgoing: $hasOutgoing) === false) {
                continue;
            }

            $id   = trim((string) $node['id']);
            $type = trim((string) ($node['type'] ?? ''));

            $warnings[] = [
                'type'   => $type,
                'app'    => $this->ownerOf(type: $type),
                'step'   => $id,
                'reason' => FlowNodePreflight::REASON_DEAD_END,
                'detail' => sprintf(
                    'Node "%s" has no outgoing edge and does not end the flow, so its token would '
                    .'arrive, run, and stop with the run reported as completed. Connect it, give it '
                    .'a terminal step type, or mark it "exit": true if stopping there is deliberate.',
                    $id
                ),
            ];
        }//end foreach

        return $warnings;

    }//end deadEnds()

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
     * mid-authoring is legitimately missing both.
     *
     * @param array $flow The flow document.
     *
     * @return array<int, array<string, mixed>> Findings, empty when both are present.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-must-have-a-trigger-and-an-end
     */
    public function entryAndExit(array $flow): array
    {
        $nodes = ($flow['nodes'] ?? []);
        if (is_array($nodes) === false || $nodes === []) {
            // An empty document is not a flow missing its ends; it is a flow
            // with nothing in it, which the author can see perfectly well.
            return [];
        }

        $hasTrigger = false;
        $hasEnd     = false;
        foreach ($nodes as $node) {
            if (is_array($node) === false) {
                continue;
            }

            $type = trim((string) ($node['type'] ?? ''));
            if ($type === '') {
                continue;
            }

            if ($this->registry->isTrigger(type: $type) === true) {
                $hasTrigger = true;
            }

            if ($this->registry->isEnd(type: $type) === true) {
                $hasEnd = true;
            }

            // `exit: true` marks a node as ending this PATH, which is what the
            // dead-end check honours. It does NOT make the flow's exit
            // deliberate in the sense this check is about: the flag is a
            // per-instance escape for migrated documents, not a node that says
            // "the flow finishes here". A flow whose only exit is a flag still
            // has no end node, and is told so.
        }//end foreach

        $findings = [];

        if ($hasTrigger === false) {
            $findings[] = [
                'type'   => '',
                'app'    => '',
                'step'   => '',
                'reason' => FlowNodePreflight::REASON_NO_TRIGGER,
                'detail' => 'This flow has no trigger node, so nothing can ever start it. '
                    .'Add a trigger — an object change, a schedule, or someone running it by hand.',
            ];
        }

        if ($hasEnd === false) {
            $findings[] = [
                'type'   => '',
                'app'    => '',
                'step'   => '',
                'reason' => FlowNodePreflight::REASON_NO_END,
                'detail' => 'This flow has no end node, so no path finishes deliberately. '
                    .'Add an End node — it may end in success or in error, but the flow must say where it stops.',
            ];
        }

        return $findings;

    }//end entryAndExit()

    /**
     * Every node id that some edge leaves from.
     *
     * `from` is the only key that matters: a node with an outgoing edge has
     * somewhere to send its token, however many edges arrive at it.
     *
     * @param array $flow The flow document.
     *
     * @return array<string, boolean> A set keyed by node id.
     *
     * @spec openspec/specs/flow-engine/spec.md
     */
    private function nodeIdsWithAnExit(array $flow): array
    {
        $hasOutgoing = [];
        foreach (($flow['edges'] ?? []) as $edge) {
            if (is_array($edge) === false) {
                continue;
            }

            $from = trim((string) ($edge['from'] ?? ''));
            if ($from !== '') {
                $hasOutgoing[$from] = true;
            }
        }//end foreach

        return $hasOutgoing;

    }//end nodeIdsWithAnExit()

    /**
     * Whether this node's token would arrive and then have nowhere to go.
     *
     * @param mixed                  $node        The node entry.
     * @param array<string, boolean> $hasOutgoing Node ids that have an exit.
     *
     * @return boolean True when the node is a dead end.
     *
     * @spec openspec/specs/flow-engine/spec.md
     */
    private function isDeadEnd(mixed $node, array $hasOutgoing): bool
    {
        if (is_array($node) === false) {
            return false;
        }

        $id = trim((string) ($node['id'] ?? ''));
        if ($id === '' || isset($hasOutgoing[$id]) === true) {
            return false;
        }

        if (($node['exit'] ?? false) === true) {
            return false;
        }

        $type = trim((string) ($node['type'] ?? ''));
        if ($type === '') {
            return false;
        }

        return ($this->registry->isEnd(type: $type) === false);

    }//end isDeadEnd()

    /**
     * The app id a namespaced node type belongs to.
     *
     * @param string $type The node type id.
     *
     * @return string The owning app id, or an empty string.
     *
     * @spec openspec/specs/flow-engine/spec.md
     */
    private function ownerOf(string $type): string
    {
        $separator = strpos($type, '.');
        if ($separator === false || $separator === 0) {
            return '';
        }

        return substr($type, 0, $separator);

    }//end ownerOf()
}//end class
