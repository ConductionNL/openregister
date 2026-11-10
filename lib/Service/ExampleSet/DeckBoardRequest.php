<?php

/**
 * OpenRegister Deck Board Request
 *
 * The board an example set puts its Deck cards on: whose, what it is called,
 * and the colour and editing groups a board written now gets.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The owner, title, colour and editing groups of one example-set Deck board.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
 */
final class DeckBoardRequest {

	/**
	 * Constructor.
	 *
	 * @param string             $owner  The board owner.
	 * @param string             $title  The board title.
	 * @param string             $color  The colour of a board written now, hex without '#'.
	 * @param array<int, string> $groups The groups that may edit a board written now.
	 */
	public function __construct(
		public readonly string $owner,
		public readonly string $title,
		public readonly string $color,
		public readonly array $groups,
	) {
	}//end __construct()
}//end class
