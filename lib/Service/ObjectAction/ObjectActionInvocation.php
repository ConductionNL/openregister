<?php

/**
 * A resolved action with the inputs it runs with.
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
 * The action a key resolved to and its inputs with defaults resolved: what the
 * precondition, the input check and the body all read.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */
final readonly class ObjectActionInvocation {

	/**
	 * Describe the invocation.
	 *
	 * @param ObjectActionDefinition $definition The resolved action.
	 * @param array<string, mixed>   $inputs     The inputs, defaults resolved.
	 */
	public function __construct(
		public ObjectActionDefinition $definition,
		public array $inputs,
	) {
	}//end __construct()
}//end class
