<?php

/**
 * Performs the side effect attached to one flow step.
 *
 * The seam between the engine (which owns *when* a step runs) and the app (which
 * owns *what* it does). FlowEngine never learns what a `synchronization` or an
 * `email` is; it hands the step here and takes back context.
 *
 * This exists so the engine is testable without a Nextcloud container, and so a
 * consuming app can contribute step types without an engine change — which is
 * the whole point of the engine living in OpenRegister rather than in the app
 * that happened to need it first (ADR-022, ADR-065).
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
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Contract for performing a flow step's side effect.
 */
interface FlowStepDispatcher
{
    /**
     * Perform one step.
     *
     * Throwing is meaningful: the engine reads the step's `onError` policy to
     * decide whether to stop, continue, or dead-letter. Swallowing an error here
     * defeats that policy and produces a run that reports success while doing
     * nothing — the exact failure mode that made OpenRegister's bulk saves write
     * zero audits.
     *
     * @param array  $step    The step configuration (the edge that carried it).
     * @param object $subject The object the run is about.
     * @param array  $context The run context so far.
     *
     * @return array|null Context to merge back, or null to leave it unchanged.
     *
     * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
     */
    public function dispatch(array $step, object $subject, array $context): ?array;
}//end interface
