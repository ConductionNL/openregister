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

use InvalidArgumentException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

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
	 * Configuration service
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Application name
	 *
	 * @var string
	 */
	private string $appName;

	/**
	 * Constructor for SearchBackendHandler
	 *
	 * @param IAppConfig $appConfig Configuration service.
	 * @param LoggerInterface $logger Logger.
	 * @param string $appName Application name.
	 *
	 * @return void
	 */
	public function __construct(
		IAppConfig $appConfig,
		LoggerInterface $logger,
		string $appName = 'openregister',
	) {
		$this->appConfig = $appConfig;
		$this->logger = $logger;
		$this->appName = $appName;
	}//end __construct()

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

	/**
	 * Update search backend configuration.
	 *
	 * Retained for API compatibility. The database backend is the only option,
	 * so any backend other than 'database' is rejected.
	 *
	 * @param string $backend Backend name (only 'database' is valid).
	 *
	 * @return (int|string[])[] Updated backend configuration.
	 *
	 * @throws \InvalidArgumentException If a non-database backend is requested.
	 *
	 * @psalm-return array{active: 'database', available: list{'database'}, updated: int<1, max>}
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-backend-agnostic-search-architecture
	 */
	public function updateSearchBackendConfig(string $backend): array {
		$availableBackends = ['database'];

		if (in_array($backend, $availableBackends, true) === false) {
			throw new InvalidArgumentException(
				"Invalid backend '$backend'. Must be one of: " . implode(', ', $availableBackends)
			);
		}

		$backendConfig = [
			'active' => 'database',
			'available' => $availableBackends,
			'updated' => time(),
		];

		$this->appConfig->setValueString($this->appName, 'search_backend', json_encode($backendConfig));

		$this->logger->info(
			message: '[SearchBackendHandler] Search backend set to: database',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'app' => 'openregister',
			]
		);

		return $backendConfig;
	}//end updateSearchBackendConfig()
}//end class
