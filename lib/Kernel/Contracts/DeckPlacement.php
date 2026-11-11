<?php

/**
 * DeckPlacement: a Deck board and a stack on it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Where a Deck card goes: the NC Deck board id and the stack id on that board.
 *
 * @spec openspec/specs/integration-deck/spec.md
 */
final class DeckPlacement {

	/**
	 * Constructor
	 *
	 * @param int $boardId NC Deck board id.
	 * @param int $stackId NC Deck stack id on that board.
	 */
	public function __construct(
		public readonly int $boardId,
		public readonly int $stackId,
	) {
	}//end __construct()
}//end class
