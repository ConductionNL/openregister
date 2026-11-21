<?php

/**
 * AutoTransitionPassInterface: the AutoTransitionPass as the auto-transition job, record listener and audit mapper reach it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * The request-scoped auto-transition pass, typed for the job that resumes it, the
 * listener that records saved objects in it and the audit mapper that reads the
 * action being applied.
 *
 * Declares only what callers outside the Service component use; the rest of the
 * class stays its own. Bound in ContractBindings to the same shared instance, so
 * the pass a caller resolves is the request's one pass.
 */
interface AutoTransitionPassInterface {

	/**
	 * Note that an object was written, so the pass will decide it.
	 *
	 * Recording is ALL that happens on the event. Nothing here evaluates a
	 * rule and nothing here writes.
	 *
	 * @param ObjectRef $ref The object's register, schema and uuid.
	 * @param array<string, mixed> $previous The object as it was before this write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function record(ObjectRef $ref, array $previous): void;

	/**
	 * The automatic transition being applied right now, if any.
	 *
	 * Read by `TransitionEngine` when it dispatches `ObjectTransitionedEvent`
	 * and by `AuditTrailMapper` when it builds the row, both of which need to
	 * mark the move without being told so by a caller.
	 *
	 * @return string|null The transition's name, or null for a manual move.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function applyingAction(): ?string;

	/**
	 * Continue a pass that was carried into a background job, from the lineage it carried.
	 *
	 * @param string $uuid The object whose lineage is being restored.
	 * @param CarriedLineage $lineage The moves made and the states visited so far.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function resumeLineage(string $uuid, CarriedLineage $lineage): void;
}//end interface
