<?php

/**
 * OpenRegister CaseItemsEnsureRequest
 *
 * A request to bring plan items over from another engine convergently:
 * where they anchor, the definition with recorded states, their history and
 * who brings them.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
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
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * The anchor, the definition, the history and the acting identity of one convergent bring-over.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
 */
final class CaseItemsEnsureRequest {

	/**
	 * Constructor.
	 *
	 * @param CaseAnchor           $anchor     The anchoring object, its register and schema.
	 * @param array<string, mixed> $definition `settings` + nested `items`; a node MAY carry `state`.
	 * @param array<int, mixed>    $history    Entries `{item, from?, to, at, actor?, reason?}`.
	 * @param string               $actor      The acting identity (`system:<app>`).
	 */
	public function __construct(
		public readonly CaseAnchor $anchor,
		public readonly array $definition,
		public readonly array $history,
		public readonly string $actor,
	) {
	}//end __construct()
}//end class
