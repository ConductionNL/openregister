<?php

/**
 * Opt-in: this node type may be run directly, out of graph, against one subject.
 *
 * Every node the engine owns runs inside a real flow run, dispatched by
 * {@see FlowEngine} after the flow's `flow.run`/editor trust already vouched
 * for the whole graph. The direct-invoke endpoint
 * (`POST /api/flows/{flowId}/nodes/{nodeId}/run`, or-flow-run-node) is a
 * different thing: it lets an app button call ONE named node of a published
 * flow on demand, without the caller ever having run — or being trusted to
 * run — the graph around it.
 *
 * RN-1 (`openspec/changes/or-flow-run-node/design.md`) decided that is safe
 * only when BOTH of two independent checks pass: the caller holds
 * object-RBAC permission on the subject (enforced by the endpoint, every
 * call, never skippable), AND the node type has deliberately said it is safe
 * to be called this way (this interface, an author decision made once, in
 * code review). Object-RBAC alone is not enough — see design.md's rejected
 * alternative (b): it ties the check to the right axis (the subject) but
 * would make every node any app ships directly callable by anyone who can
 * write to the right kind of object, the moment a manifest action points a
 * button at it, whether or not the node's author ever intended standalone
 * use. A node that sends an external notification, or advances a legal
 * deadline, might be correct only as one step inside a supervised graph.
 *
 * A node type that does NOT implement this interface is unreachable through
 * the direct-invoke endpoint — 404, indistinguishable from a node id that
 * does not exist at all, so there is no oracle for "this node exists but is
 * not invokable this way" versus "no such node".
 *
 * WHY A SEPARATE INTERFACE AND NOT A FLAG ON `IFlowNode`
 * -------------------------------------------------------
 * Same reasoning as {@see IFlowNodeConfigKeys} and
 * {@see IFlowSelfScopedNode}: `IFlowNode` implementations live in other
 * repositories on their own release cycles (openconnector, hermiq, dossiq),
 * and adding a required method to `IFlowNode` itself is a fatal error for
 * every one of them until updated. An optional interface, probed with
 * `instanceof`, costs nothing to a node that never opts in — which is every
 * node until its author deliberately implements this one.
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
 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md#requirement-a-node-type-opts-in-to-direct-invocation
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Marks a node type as safe to invoke directly, out of graph, on one subject.
 */
interface IFlowDirectlyInvokable {

}//end interface
