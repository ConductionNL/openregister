<?php

/**
 * OpenRegister schema cache clearers
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Settings;

use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearer;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;

/**
 * The two schema-level caches the cache settings clear: the schema cache and
 * the facet result cache built from schemas.
 *
 * @spec openspec/specs/settings-management/spec.md#requirement-the-capability-must-orchestrate-cache-statistics-clearing-and-warmup
 */
final class SchemaCacheClearers {
	/**
	 * Bundle the clearers.
	 *
	 * @param SchemaCacheHandler      $schemaCache  Schema cache handler.
	 * @param FacetResultCacheClearer $facetResults Clears the facet result caches.
	 *
	 * @spec openspec/specs/settings-management/spec.md#requirement-the-capability-must-orchestrate-cache-statistics-clearing-and-warmup
	 */
	public function __construct(
		public readonly SchemaCacheHandler $schemaCache,
		public readonly FacetResultCacheClearer $facetResults,
	) {
	}//end __construct()
}//end class
