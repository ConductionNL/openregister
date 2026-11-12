<?php

/**
 * OpenRegister SearchTermLiteral
 *
 * The literal part of a {@see SearchTermNode}: the text the user typed, with
 * its wildcards stripped, and which ends carried one. A boolean node carries
 * the empty literal.
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
 * A literal search term and its wildcard flags.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */
final readonly class SearchTermLiteral {

	/**
	 * Constructor.
	 *
	 * @param string $value            Literal text, wildcards already stripped.
	 * @param bool   $leadingWildcard  Whether the term began with `*`.
	 * @param bool   $trailingWildcard Whether the term ended with `*`.
	 */
	public function __construct(
		public string $value = '',
		public bool $leadingWildcard = false,
		public bool $trailingWildcard = false,
	) {
	}//end __construct()
}//end class
