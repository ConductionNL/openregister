<?php

/**
 * One place an anonymisation has to reach.
 *
 * The payload is one. The search index is another. The stored diffs on the
 * audit trail are a third. Each is a separate store with its own failure mode,
 * and the act is only an anonymisation if it reaches all of them.
 *
 * TWO PHASES, AND THE SPLIT IS THE WHOLE POINT. `prepare()` does everything
 * that can fail without writing anything: resolve the rows, check the
 * permission, hold the handle. `apply()` does the write and is entitled to
 * assume its preparation succeeded. A run prepares every target before it
 * applies any, so a target that cannot be reached stops the act while the
 * record is still whole.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * A store an anonymisation must reach, in two phases.
 */
interface AnonymisationTarget {

	/**
	 * What this target is, for the refusal and the report.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
	 */
	public function name(): string;

	/**
	 * Do everything that can fail, and write nothing.
	 *
	 * @param AnonymisationPlan $plan What is being changed and what is kept.
	 *
	 * @return void
	 *
	 * @throws \Throwable When this target cannot be reached.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
	 */
	public function prepare(AnonymisationPlan $plan): void;

	/**
	 * Write, having prepared.
	 *
	 * @param AnonymisationPlan $plan What is being changed and what is kept.
	 *
	 * @return void
	 *
	 * @throws \Throwable When the write fails despite preparation.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
	 */
	public function apply(AnonymisationPlan $plan): void;
}//end interface
