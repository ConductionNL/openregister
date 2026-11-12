<?php

/**
 * OpenRegister Configuration Cache Handler
 *
 * This file contains the handler for caching configurations in the user session
 * to avoid excessive database queries when checking if entities are managed by configurations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapperInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\ISession;

/**
 * CacheHandler caches configurations in user session
 *
 * Handler for caching configurations in user session to avoid excessive database
 * queries when checking if entities are managed by configurations. Uses session-based
 * caching per organisation for optimal performance.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */
class CacheHandler {
	/**
	 * Session key prefix for storing configurations
	 *
	 * Prefix used for session keys to store cached configurations per organisation.
	 *
	 * @var string Session key prefix
	 */
	private const SESSION_KEY_PREFIX = 'openregister_configurations_';

	/**
	 * CacheHandler constructor
	 *
	 * @param ISession $session Session for caching
	 * @param ConfigurationMapperInterface $configurationMapper Mapper for configurations
	 * @param OrganisationServiceInterface $organisationService Organisation service
	 */
	public function __construct(
		private readonly ISession $session,
		private readonly ConfigurationMapperInterface $configurationMapper,
		private readonly OrganisationServiceInterface $organisationService,
	) {
	}//end __construct()

	/**
	 * Get configurations for the active organisation
	 *
	 * Returns configurations from session cache if available, otherwise fetches
	 * from database and caches in session. Cache is keyed by organisation UUID
	 * to support multi-tenancy.
	 *
	 * @return Configuration[] Array of configuration entities for active organisation
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-metadata-facets-via-self-namespace
	 */
	public function getConfigurationsForActiveOrganisation(): array {
		// Step 1: Get active organisation from organisation service.
		$activeOrg = $this->organisationService->getActiveOrganisation();
		if ($activeOrg === null) {
			// No active organisation - return empty array.
			return [];
		}

		// Step 2: Build session cache key using organisation UUID.
		// This ensures cache is isolated per organisation.
		$orgUuid = $activeOrg->getUuid();
		$sessionKey = self::SESSION_KEY_PREFIX . $orgUuid;

		// Step 3: Check if configurations are cached in session.
		$cachedData = $this->session->get($sessionKey);
		if (is_string($cachedData) === true) {
			// SEC-SVC-10: restrict unserialize to the expected entity/value
			// classes so a tampered session blob cannot instantiate arbitrary
			// objects (PHP object injection).
			// NOSEMGREP: php.lang.security.unserialize-use.unserialize-use -- Only Configuration and DateTime may load; only a Configuration list is used.
			$cached = unserialize(
				$cachedData,
				['allowed_classes' => [Configuration::class, \DateTime::class]]
			);
			if ($this->isConfigurationList(value: $cached) === true) {
				return $cached;
			}
		}

		// Step 4: Not cached - fetch configurations from database.
		$configurations = $this->configurationMapper->findAll();

		// Step 5: Cache configurations in session for future requests.
		$this->session->set($sessionKey, serialize($configurations));

		// Step 6: Return fetched configurations.
		return $configurations;
	}//end getConfigurationsForActiveOrganisation()

	/**
	 * Whether a value read back from the session is a list of configurations.
	 *
	 * @param mixed $value The unserialized value.
	 *
	 * @return bool True when it is an array holding only Configuration entities.
	 */
	private function isConfigurationList(mixed $value): bool {
		if (is_array($value) === false) {
			return false;
		}

		foreach ($value as $configuration) {
			if ($configuration instanceof Configuration === false) {
				return false;
			}
		}

		return true;
	}//end isConfigurationList()
}//end class
