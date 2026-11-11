<?php

/**
 * OpenRegister SearchQueryParts
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

namespace OCA\OpenRegister\Service\Object;


/**
 * Request parameters sorted into the parts of a search query.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class SearchQueryParts {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $self          The `@self` metadata filters.
	 * @param array<string, mixed> $special       The underscore parameters (`_limit`, `_order`, ...).
	 * @param array<string, mixed> $objectFilters The object field filters.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		public readonly array $self,
		public readonly array $special,
		public readonly array $objectFilters,
	) {
	}//end __construct()
}//end class
