<?php

/**
 * OpenRegister TokenizeRun
 *
 * The state of one tokenize pass of {@see SearchTermParser}: the term being
 * split and its length in characters, shared by every token reader.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

/**
 * One tokenize pass over a search term (ADR-011 Rule 3 run object).
 *
 * Never leaves SearchTermParser.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */
final class TokenizeRun {

	/**
	 * Constructor.
	 *
	 * @param string $term   The raw term.
	 * @param int    $length The term's length in characters.
	 */
	public function __construct(
		public readonly string $term,
		public readonly int $length,
	) {
	}//end __construct()
}//end class
