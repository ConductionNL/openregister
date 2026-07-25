<?php

/**
 * Resolves the two things a queued run needs before it can execute: the flow
 * document, and the object the run is about.
 *
 * The engine deliberately does not know where flows are stored. hermiq keeps
 * them as `agentflow` objects; a future app might keep them elsewhere; the
 * worker must not care. So an app that owns flows contributes a resolver —
 * discovered the same way node types and MCP providers are, through a
 * registration event — and the worker asks it.
 *
 * Returning null for either is not an error the run should crash on: a flow
 * that was deleted, or a subject that no longer exists, means the run cannot
 * proceed and should be recorded as such, not throw the worker's whole batch.
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
 * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Contract for loading a flow document and its subject.
 */
interface IFlowResolver
{
    /**
     * Load a flow document by its id.
     *
     * @param string $flowId The flow's id.
     *
     * @return array|null The flow document (`{id, nodes, edges, ...}`), or null
     *                    when this resolver does not own that flow.
     *
     * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
     */
    public function resolveFlow(string $flowId): ?array;

    /**
     * Load the object a run walks against.
     *
     * @param string $uuid     The subject object's uuid.
     * @param string $register The register slug.
     * @param string $schema   The schema slug.
     *
     * @return object|null The subject (carries the marking + is seeded into the
     *                     item list), or null when it cannot be found.
     *
     * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
     */
    public function resolveSubject(string $uuid, string $register, string $schema): ?object;

    /**
     * Which of this resolver's flows should run for an event.
     *
     * Called by the trigger side: when an object event fires, every resolver is
     * asked which of its enabled flows are wired to that event on that
     * register/schema, and a run is queued for each. A resolver that owns no
     * matching flow returns an empty array.
     *
     * @param string $event    The event id (e.g. `object.created`).
     * @param string $register The register slug the event fired on.
     * @param string $schema   The schema slug the event fired on.
     *
     * @return array<int, string> The ids of the flows to run.
     *
     * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
     */
    public function flowsForTrigger(string $event, string $register, string $schema): array;
}//end interface
