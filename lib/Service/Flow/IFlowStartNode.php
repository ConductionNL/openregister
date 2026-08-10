<?php

/**
 * A node a run may begin at.
 *
 * WHY THIS EXISTS
 * ---------------
 * The engine already knew which nodes STOP a path ({@see IFlowStopNode}) but
 * had no way to say which ones START one. Everything downstream therefore
 * GUESSED, by matching the node's id against a naming convention — hermiq's
 * palette did exactly that:
 *
 *     if (id.includes('.trigger-')) return 'trigger'
 *     if (id.endsWith('.stop'))     return 'terminal'
 *
 * That is the hardcoded list {@see FlowNodeRegistry::isStop()} deliberately
 * avoids, reintroduced one layer up. A start node contributed by another app —
 * openconnector, hermiq, or one not written yet — is a start node whatever it
 * is called, and a convention that lives in a consumer's string match is one
 * every consumer has to reimplement and none of them can be told about.
 *
 * So the node says so, the registry reports it, and the palette ships it.
 *
 * WHY NOT A METHOD ON `IFlowNode`
 * -------------------------------
 * Same reason as {@see IFlowStopNode}: adding a method to `IFlowNode` fatals
 * every implementation that has not been updated, and implementations live in
 * OTHER repositories — openconnector ships `source-call` and
 * `synchronization-run`, hermiq ships `agent-step` and `workload-step`.
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
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-declares-whether-it-starts-or-stops-a-path
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Optional companion to {@see IFlowNode}: a run may begin at this node.
 *
 * A marker interface — it declares no methods, for the same reason
 * {@see IFlowStopNode} declares none: starting is a property of the node TYPE,
 * so `instanceof` is the whole contract.
 *
 * Implementing this does NOT make the node fire. What a start node subscribes
 * to is its CONFIG — an object event, a cron expression, a person pressing run
 * — and the resolver reads that. This says only "a run may begin here", which
 * is what the palette, the connectivity check and an author reading the canvas
 * each need to know.
 */
interface IFlowStartNode
{
}//end interface
