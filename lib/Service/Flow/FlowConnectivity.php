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
 * purpose" ({@see IFlowStopNode}), OR-ed and never AND-ed:
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

        return ($this->registry->isStop(type: $type) === false);

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
