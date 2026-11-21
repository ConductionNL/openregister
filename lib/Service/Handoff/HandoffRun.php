<?php

/**
 * OpenRegister HandoffRun
 *
 * One executing handoff once its target exists: the plan and the created target.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Handoff;

/**
 * One executing handoff once its target exists: the plan and the created target.
 *
 * Built by {@see HandoffService} after the target create and passed to its
 * provenance, audit and event helpers; it never leaves that class (ADR-011 Rule 3).
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */
final class HandoffRun {

	/**
	 * Name the run.
	 *
	 * @param HandoffPlan   $plan   The plan (request, source, source schema, declared entry).
	 * @param HandoffTarget $target The created target object, in its magic-table context.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	public function __construct(
		public readonly HandoffPlan $plan,
		public readonly HandoffTarget $target,
	) {
	}//end __construct()
}//end class
