<?php

/**
 * FlowRunContextInterface: the FlowRunContext as the audit attribution, the delete guard and the objects controller read it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The executing flow step, read-only, for the callers outside the Service component
 * that attribute or authorise a write by the run that makes it.
 *
 * Declares only what those callers use; push() and pop() stay the engine's own.
 * Bound in ContractBindings to FlowRunContext, whose FlowRegistrar registration is
 * shared, so every caller reads the one ambient stack.
 */
interface FlowRunContextInterface {

	/**
	 * The frame to attribute a write to, or null when nothing is executing.
	 *
	 * @return array{run: string, node: string, step: int}|null The innermost frame, or null outside any run.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	public function current(): ?array;

	/**
	 * The uuid of the run currently executing, or null outside any run.
	 *
	 * WHY A WRITE GUARD ASKS THIS. A run-scoped lock refuses every caller but
	 * the holding run — the run's own `runAs` user included, which is the
	 * point. A guard that cannot name the caller's run therefore refuses the
	 * run that took the lock: the flow locks a case and is then refused by its
	 * own lock at the next write. This is the seam that answers "which run is
	 * writing", and it is ambient for the same reason attribution is: the
	 * write may be several calls deep inside a leaf app that has never heard
	 * of flows.
	 *
	 * Null outside a run, and null for a hop that is not attributable. Both
	 * read as "a person is writing", which is the FAIL-CLOSED direction: a run
	 * lock refuses a caller with no run uuid.
	 *
	 * @return string|null The executing run's uuid, or null.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-ownership-is-decided-by-one-predicate
	 */
	public function currentRunUuid(): ?string;
}//end interface
