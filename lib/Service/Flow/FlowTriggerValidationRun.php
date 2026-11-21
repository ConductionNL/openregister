<?php

/**
 * One trigger validation pass over a flow about to be written.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The node registry and the saving identity, both resolved once per flow.
 *
 * Built by {@see FlowTriggerValidator::validate()} and never leaves that class.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class FlowTriggerValidationRun {
	/**
	 * Constructor.
	 *
	 * @param object      $registry The node registry.
	 * @param string|null $savedBy  The uid performing the save, or null when code-initiated.
	 */
	public function __construct(
		public readonly object $registry,
		public readonly ?string $savedBy,
	) {
	}//end __construct()
}//end class
