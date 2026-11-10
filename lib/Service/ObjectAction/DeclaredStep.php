<?php

/**
 * One step of a declared steps action, at its position.
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
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-inline-steps-are-a-straight-line-lowered-to-the-one-flow-engine
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

/**
 * The action a step belongs to, the step's position (from 1) and the step itself.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-inline-steps-are-a-straight-line-lowered-to-the-one-flow-engine
 */
final readonly class DeclaredStep {

	/**
	 * Describe the step.
	 *
	 * @param string               $key      The action key.
	 * @param int                  $position The step's position, from 1.
	 * @param array<string, mixed> $step     The step.
	 */
	public function __construct(
		public string $key,
		public int $position,
		public array $step,
	) {
	}//end __construct()
}//end class
