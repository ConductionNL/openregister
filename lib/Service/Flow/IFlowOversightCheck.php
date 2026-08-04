<?php

/**
 * A gate consulted before each hop of a flow run.
 *
 * The engine deliberately knows nothing about what an oversight check means. A
 * kill switch, a spend budget, a rate limit and a maintenance window are all the
 * same shape to it: something that may refuse the next hop and say why. Apps
 * contribute checks the same way they contribute node types — through a
 * registration event — so the engine never grows an app-specific branch.
 *
 * This exists because the safety rails that guarded hermiq's agent runs were
 * written into hermiq's own executor, which meant they applied to exactly one
 * node type in exactly one app. Making the gate engine-wide is what lets a
 * budget stop an `openregister.object-write` hop that a flow reached THROUGH an
 * agent step, not just the agent step itself.
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
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Contract for a pre-hop oversight check.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */
interface IFlowOversightCheck
{
    /**
     * Stable id, namespaced by the contributing app (`{app}.{check}`).
     *
     * Recorded on the step row when this check vetoes, so a stopped run names
     * what stopped it rather than reporting a bare refusal.
     *
     * @return string The check's id.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
     */
    public function getId(): string;

    /**
     * Decide whether the next hop may execute.
     *
     * Returning a reason string is a VETO; returning null is consent. The
     * asymmetry is deliberate: a check that cannot form an opinion must return
     * null explicitly, so "no objection" is always a decision rather than the
     * result of an exception being swallowed. A check that throws is treated by
     * the registry as a veto, never as consent.
     *
     * @param array<string, mixed> $context The run context: flow id, run uuid,
     *                                      owner, organisation, the node about
     *                                      to execute and its type.
     *
     * @return string|null The reason for refusing, or null to allow the hop.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
     */
    public function veto(array $context): ?string;
}//end interface
