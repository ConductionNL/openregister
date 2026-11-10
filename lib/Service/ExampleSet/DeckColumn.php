<?php

/**
 * OpenRegister Deck Column
 *
 * One column of one Deck table that points at a row id, as the example-set
 * Deck store deletes by it.
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
 * A Deck table, without prefix, and the column in it that holds an id.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
 */
final class DeckColumn {

	/**
	 * The table, without prefix.
	 *
	 * @var string
	 */
	public readonly string $table;

	/**
	 * The column holding the id.
	 *
	 * @var string
	 */
	public readonly string $column;

	/**
	 * Constructor.
	 *
	 * @param string $table  The table, without prefix.
	 * @param string $column The column holding the id.
	 */
	public function __construct(string $table, string $column) {
		$this->table  = $table;
		$this->column = $column;
	}//end __construct()
}//end class
