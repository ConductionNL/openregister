<?php

/**
 * OpenRegister Search Backend Settings Handler
 *
 * This file contains the handler class for managing search backend configuration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-backend-agnostic-search-architecture
 */

namespace OCA\OpenRegister\Service\Settings;

/**
 * Handler for search backend settings operations.
 *
 * This handler reports the active search backend. Since the external Solr and
 * Elasticsearch backends were removed, the built-in database (Magic-Tables)
 * search is the sole backend.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */
class SearchBackendHandler {

	/**
	 * Get search backend configuration.
	 *
	 * The external Solr/Elasticsearch backends were removed; the built-in
	 * database (Magic-Tables) search is the only backend.
	 *
	 * @return array Backend configuration with 'active' key.
	 *
	 * @throws \RuntimeException If backend configuration retrieval fails.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-backend-agnostic-search-architecture
	 */
	public function getSearchBackendConfig(): array {
		return [
			'active' => 'database',
			'available' => ['database'],
		];
	}//end getSearchBackendConfig()
}//end class
