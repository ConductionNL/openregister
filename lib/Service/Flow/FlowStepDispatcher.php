<?php

/**
 * Performs the side effect attached to one flow step.
 *
 * The seam between the engine (which owns *when* a step runs) and the app (which
 * owns *what* it does). FlowEngine never learns what a `synchronization` or an
 * `email` is; it hands the step its input items and takes back output items.
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
interface FlowStepDispatcher {
	/**
	 * Perform one step: items in, items out.
	 *
	 * The data channel is a LIST of items ({@see FlowItems}), not a single
	 * object. A step that acts per record acts once per item and returns one
	 * item per result; a step that filters returns fewer items than it got, and
	 * an empty list legitimately ends that branch's data.
	 *
	 * `$context` is run-level metadata (who triggered it, the run id, the
	 * subject) — not the data channel. Putting records there instead of in the
	 * returned items is the mistake this contract exists to prevent: context is
	 * shared by the whole run, so anything written there stops being per-record
	 * the moment a step fans out.
	 *
	 * Throwing is meaningful: the engine reads the step's `onError` policy to
	 * decide whether to stop, continue, or dead-letter. Swallowing an error here
	 * defeats that policy and produces a run that reports success while doing
	 * nothing — the exact failure mode that made OpenRegister's bulk saves write
	 * zero audits.
	 *
	 * @param array $step The step configuration (the edge that carried it).
	 * @param array $items The input items for this step.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The output items. Normalised by the engine, so returning a
	 *               single item or a bare record is accepted.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function dispatch(array $step, array $items, array $context): array;
}//end interface
