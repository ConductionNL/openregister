<?php

/**
 * OpenRegister PartyCarryOver
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\Support;

use OCA\OpenRegister\Service\Party\PartyDefinition;

/**
 * One merged-away party carried onto the survivor by one merge operation.
 *
 * @spec openspec/specs/mdm-merge/spec.md#requirement-parties-merge-through-the-existing-merge-primitive-req-prm-005
 */
final class PartyCarryOver {
	/**
	 * Bundle the carry-over.
	 *
	 * @param string          $survivorUuid The surviving party.
	 * @param string          $fromUuid     The party merged away.
	 * @param string          $operationId  The merge operation.
	 * @param PartyDefinition $definition   The survivor's party declaration.
	 *
	 * @spec openspec/specs/mdm-merge/spec.md#requirement-parties-merge-through-the-existing-merge-primitive-req-prm-005
	 */
	public function __construct(
		public readonly string $survivorUuid,
		public readonly string $fromUuid,
		public readonly string $operationId,
		public readonly PartyDefinition $definition,
	) {
	}//end __construct()

	/**
	 * The memo a link this carry-over touched records, for the reversal.
	 *
	 * @param string $mode How the link was carried: `moved` or `absorbed`.
	 *
	 * @return array{operation: string, from: string, mode: string} The memo.
	 *
	 * @spec openspec/specs/mdm-merge/spec.md#requirement-parties-merge-through-the-existing-merge-primitive-req-prm-005
	 */
	public function memo(string $mode): array {
		return ['operation' => $this->operationId, 'from' => $this->fromUuid, 'mode' => $mode];
	}//end memo()
}//end class
