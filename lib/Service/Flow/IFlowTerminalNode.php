<?php

/**
 * A node that deliberately ends a path.
 *
 * WHY THIS EXISTS
 * ---------------
 * After `or-flow-action-nodes` a node with no outgoing edge is a **dead end**:
 * its token arrives, the step runs, and the run stops there with nothing to
 * consume it. The engine reports the run COMPLETED, because from its point of
 * view nothing failed — it simply ran out of places to go. That is the failure
 * mode this interface exists to make impossible to hide: a flow must either
 * exit DELIBERATELY or say it is broken, never stop quietly and call it success.
 *
 * The connectivity check therefore needs to distinguish "this node ends the
 * path because ending is what it does" from "this node ends the path because
 * its author forgot to connect it". Only the node itself knows which, so it
 * says so — by implementing this.
 *
 * WHY NOT A METHOD ON `IFlowNode`
 * -------------------------------
 * Adding a method to `IFlowNode` is a fatal error for every class implementing
 * it that has not been updated, and implementations live in OTHER repositories:
 * openconnector ships `source-call` and `synchronization-run`, hermiq ships
 * `agent-step` and `workload-step`. Publishing a release that fatals those apps
 * on load is a worse outcome than the defect being closed. This is the same
 * reasoning, and the same shape, as {@see IFlowNodeConfigKeys} — and the same
 * precedent in core, where `ISpecificOperation` extends `IOperation` rather
 * than widening it and core probes with `instanceof`.
 *
 * THE ESCAPE HATCH
 * ----------------
 * A node can also be marked terminal per-instance with `exit: true` on the node
 * in the flow document, without its TYPE being terminal. That is what a
 * migrated flow needs: a sink whose step type is an ordinary action, which was
 * a legitimate end of a path under the old place-and-edge reading and must not
 * start being refused because the reading changed.
 *
 * The two answers are deliberately OR-ed, never AND-ed: a registered terminal
 * type does not need the flag, and the flag does not need the type's
 * permission. Requiring both would make every migrated flow depend on a
 * registry the migration cannot see.
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
 * Optional companion to {@see IFlowNode}: this node ends a path on purpose.
 *
 * A marker interface — it declares no methods. Terminality is a property of the
 * node TYPE, not a question the engine asks with arguments, so there is nothing
 * to pass and nothing to answer. `instanceof` is the whole contract.
 *
 * A node that does NOT implement this is not assumed non-terminal in any
 * damaging way: it is simply expected to have an outgoing edge, and warns when
 * it does not. The warning never blocks a save.
 */
interface IFlowTerminalNode
{
}//end interface
