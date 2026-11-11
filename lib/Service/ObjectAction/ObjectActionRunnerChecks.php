<?php

/**
 * The collaborators the action runner checks a run with, before anything runs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

/**
 * Resolve, right and precondition, inputs: what the runner asks before a body runs.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */
final class ObjectActionRunnerChecks {

	/**
	 * Constructor.
	 *
	 * @param ObjectActionDefinitions $definitions The schema's actions, inherited ones included.
	 * @param ObjectActionGate        $gate        The right and the precondition.
	 * @param ObjectActionInputs      $inputs      Defaults, validation and digest.
	 */
	public function __construct(
		public readonly ObjectActionDefinitions $definitions,
		public readonly ObjectActionGate $gate,
		public readonly ObjectActionInputs $inputs,
	) {
	}//end __construct()
}//end class
