<?php

/**
 * One object a step claims under a declared subject role.
 *
 * The role, the object and where it lives travel together from the step that
 * did the work to the run row that remembers it, so they are one value.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-run-subjects/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * A role, the object recorded under it, and that object's register and schema.
 *
 * @spec openspec/specs/flow-run-subjects/spec.md
 */
final class FlowSubjectClaim {

	/**
	 * Constructor.
	 *
	 * @param string $role     The role the step declared, or ''.
	 * @param string $uuid     The object it worked on.
	 * @param string $register Its register, when known.
	 * @param string $schema   Its schema, when known.
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	public function __construct(
		public readonly string $role,
		public readonly string $uuid,
		public readonly string $register = '',
		public readonly string $schema = '',
	) {

	}//end __construct()
}//end class
