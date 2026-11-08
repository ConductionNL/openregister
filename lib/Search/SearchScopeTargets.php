<?php

/**
 * SearchScopeTargets: what the scopes of one search narrow it to.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search;

/**
 * The app ids, register slugs and schema slugs a search keeps.
 *
 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md
 */
final class SearchScopeTargets {
	/**
	 * Constructor.
	 *
	 * @param array<int, string> $apps      App ids to keep.
	 * @param array<int, string> $registers Register slugs to keep.
	 * @param array<int, string> $schemas   Schema slugs to keep.
	 */
	public function __construct(
		public readonly array $apps,
		public readonly array $registers,
		public readonly array $schemas,
	) {
	}//end __construct()
}//end class
