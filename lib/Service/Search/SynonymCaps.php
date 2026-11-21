<?php

/**
 * OpenRegister SynonymCaps
 *
 * How many synonyms a dictionary expansion may add: per word and across the
 * whole term.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

/**
 * The caps on one dictionary expansion (ADR-011 Rule 2 value object).
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */
final class SynonymCaps {

	/**
	 * Constructor.
	 *
	 * @param int $perGroup Most synonyms added per word.
	 * @param int $perQuery Most synonyms added across the whole term.
	 */
	public function __construct(
		public readonly int $perGroup,
		public readonly int $perQuery,
	) {
	}//end __construct()
}//end class
