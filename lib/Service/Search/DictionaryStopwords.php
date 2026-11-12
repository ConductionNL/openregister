<?php

/**
 * What the dictionary's stopwords did to a query.
 *
 * The stopwords dropped from the term, and whether the term as typed stood
 * anyway because dropping them would have left nothing to search for. Part
 * of a {@see DictionaryExpansion}.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

/**
 * The stopwords a search dropped, or would have.
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */
final readonly class DictionaryStopwords {

	/**
	 * Constructor.
	 *
	 * @param string[] $removed Stopwords the dictionary dropped (or would have, when kept).
	 * @param bool     $kept    Whether the original stood because removal would have emptied it.
	 */
	public function __construct(
		public array $removed = [],
		public bool $kept = false,
	) {
	}//end __construct()
}//end class
