<?php

/**
 * OpenRegister Deck Titled
 *
 * A Deck row by its title under the value it points at, as the example-set
 * Deck store looks a board, list or label up.
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
 * The value a Deck row points at (a board's owner, a list's or label's board id) and the row's title.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
 */
final class DeckTitled {

	/**
	 * The value the row points at: an owner or a board id.
	 *
	 * @var integer|string
	 */
	public readonly int|string $parent;

	/**
	 * The row's title.
	 *
	 * @var string
	 */
	public readonly string $title;

	/**
	 * Constructor.
	 *
	 * @param integer|string $parent The value the row points at: an owner or a board id.
	 * @param string         $title  The row's title.
	 */
	public function __construct(int|string $parent, string $title) {
		$this->parent = $parent;
		$this->title  = $title;
	}//end __construct()
}//end class
