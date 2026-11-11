<?php

/**
 * Writing one plan to its targets, one after another.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The plan being written and the targets it has reached so far.
 *
 * Never leaves AnonymisationRun.
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */
final class TargetWriteRun {
	/**
	 * The targets already written, in order.
	 *
	 * @var string[]
	 */
	public array $applied = [];

	/**
	 * Start writing a plan.
	 *
	 * @param AnonymisationPlan $plan The plan.
	 */
	public function __construct(
		public readonly AnonymisationPlan $plan,
	) {
	}//end __construct()
}//end class
