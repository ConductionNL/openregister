<?php

/**
 * OpenRegister Handoff Kind Contract
 *
 * The contract fields of one target kind, read from {@see HandoffKindContracts}
 * once per entry and handed to {@see HandoffMappingChecks}.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Handoff;

/**
 * The contract a handoff entry's target kind carries, if any.
 */
final class HandoffKindContract {

	/**
	 * Constructor.
	 *
	 * @param string             $kindUri         The target kind URI.
	 * @param bool               $isContractKind  Whether the kind carries a contract.
	 * @param array<int, string> $allFields       Every contract field, mandatory first.
	 * @param array<int, string> $mandatoryFields The fields every mapping must cover.
	 */
	public function __construct(
		public readonly string $kindUri,
		public readonly bool $isContractKind,
		public readonly array $allFields,
		public readonly array $mandatoryFields,
	) {

	}//end __construct()

	/**
	 * Whether a mapped field falls outside the kind's contract.
	 *
	 * A kind without a contract constrains nothing, so no field is foreign to it.
	 *
	 * @param string $field The mapped field.
	 *
	 * @return bool True when the kind has a contract that lacks the field.
	 */
	public function isForeign(string $field): bool {
		return $this->isContractKind === true && in_array($field, $this->allFields, true) === false;
	}//end isForeign()
}//end class
