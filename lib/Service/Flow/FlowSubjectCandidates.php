<?php

/**
 * The objects a step acted on, offered for one declared subject role.
 *
 * A role names one object, so this set only becomes a claim when it holds
 * exactly one uuid. See {@see FlowRunSubjectRecorder::recordOne()}.
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
 * A role, the distinct objects a step acted on, and their register and schema.
 *
 * @spec openspec/specs/flow-run-subjects/spec.md
 */
final class FlowSubjectCandidates {

	/**
	 * Constructor.
	 *
	 * @param string             $role     The role the step named, or '' for none.
	 * @param array<int, string> $uuids    The distinct objects the step acted on.
	 * @param string             $register Their register, when the step resolved one.
	 * @param string             $schema   Their schema, when the step resolved one.
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	public function __construct(
		public readonly string $role,
		public readonly array $uuids,
		public readonly string $register = '',
		public readonly string $schema = '',
	) {

	}//end __construct()

	/**
	 * The claim for the one object, or null when there is not exactly one.
	 *
	 * @return FlowSubjectClaim|null The claim.
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	public function sole(): ?FlowSubjectClaim {
		if (count($this->uuids) !== 1) {
			return null;
		}

		return new FlowSubjectClaim(
			role: $this->role,
			uuid: $this->uuids[array_key_first($this->uuids)],
			register: $this->register,
			schema: $this->schema
		);

	}//end sole()
}//end class
