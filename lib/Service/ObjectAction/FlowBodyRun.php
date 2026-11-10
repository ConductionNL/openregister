<?php

/**
 * One flow or steps body run of a declared action.
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
 * The target, the checked action and its storable inputs (writeOnly ones held
 * aside) of one flow or steps body. Never leaves ObjectActionFlowRun.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */
final readonly class FlowBodyRun {

	/**
	 * Describe the run.
	 *
	 * @param ObjectActionTarget     $target     The subject, its schema and its address as the caller named it.
	 * @param ObjectActionDefinition $definition The checked action.
	 * @param array<string, mixed>   $storable   The inputs without writeOnly ones.
	 */
	public function __construct(
		public ObjectActionTarget $target,
		public ObjectActionDefinition $definition,
		public array $storable,
	) {
	}//end __construct()
}//end class
