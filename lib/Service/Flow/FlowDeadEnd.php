<?php

/**
 * Thrown when a flow is asked to run but cannot finish.
 *
 * WHY A REFUSAL AND NOT A WARNING
 * -------------------------------
 * A dead-ended flow does not fail — that is the whole problem. Its token
 * arrives at a node with nowhere to go, the engine finds no enabled transition,
 * the run stops, and it is recorded COMPLETED. The author sees a green run that
 * did not do the work. Running it and reporting success is therefore strictly
 * worse than refusing to start: the refusal is loud, names the nodes, and can
 * be acted on.
 *
 * SAVING is not refused, only RUNNING. A half-wired graph is the normal state
 * of one being authored, and an editor that refuses to save until the graph is
 * complete would require authors to build it in an order that is never
 * disconnected — which no editor can require. So the warning lands at save
 * ({@see FlowNodePreflight::REASON_DEAD_END}) and the refusal lands here.
 *
 * WHERE IT IS RAISED
 * ------------------
 * `FlowRunService::queue()`, which is the single choke point every dispatch
 * path passes through: manual `POST /run`, trigger, schedule, MCP, the
 * workflow-engine operation, and a sub-flow invocation. Guarding the manual
 * path alone would leave cron-fired flows unguarded, and those are most of
 * them.
 *
 * CATCHING IT
 * -----------
 * The schedule sweep catches it PER FLOW. It iterates every due flow, so
 * letting this propagate would abort the sweep and stop every later flow from
 * firing — one broken flow silently disabling the rest is a bigger outage than
 * the one being reported.
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

use RuntimeException;

/**
 * A flow was asked to run while it has a node that cannot pass its token on.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class FlowDeadEnd extends RuntimeException
{
    /**
     * Constructor.
     *
     * @param array<int, string> $nodeIds The offending node ids, in document order.
     *
     * @spec openspec/specs/flow-engine/spec.md
     */
    public function __construct(private readonly array $nodeIds=[])
    {
        $subject = sprintf('nodes "%s" have', implode(separator: '", "', array: $nodeIds));
        $pronoun = 'them';

        if (count($nodeIds) === 1) {
            $subject = sprintf('node "%s" has', $nodeIds[0]);
            $pronoun = 'it';
        }

        parent::__construct(
            message: sprintf(
                'This flow is not runnable: %s no outgoing edge and does not end the flow, so a run '
                .'would stop there and still be reported as completed. Connect %s, give %s a terminal '
                .'step type, or mark %s "exit": true if stopping there is deliberate.',
                $subject,
                $pronoun,
                $pronoun,
                $pronoun
            )
        );

    }//end __construct()

    /**
     * The nodes that caused the refusal.
     *
     * @return array<int, string> The node ids.
     *
     * @spec openspec/specs/flow-engine/spec.md
     */
    public function getNodeIds(): array
    {
        return $this->nodeIds;

    }//end getNodeIds()
}//end class
