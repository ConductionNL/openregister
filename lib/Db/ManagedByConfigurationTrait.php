<?php

/**
 * OpenRegister ManagedByConfigurationTrait
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

/**
 * Whether, and by which configuration, the using entity is managed.
 *
 * A configuration manages the entity when its member list for the entity's
 * kind (registers, sources, applications ...) holds the entity's id, compared
 * strictly. An entity without an id is never managed.
 *
 * @spec exclude shared entity plumbing, not a capability of its own
 */
trait ManagedByConfigurationTrait {

	/**
	 * Check if this entity is managed by any of the configurations.
	 *
	 * @param array<Configuration> $configurations Array of Configuration entities to check against
	 *
	 * @return bool True if this entity is managed by at least one configuration
	 *
	 * @phpstan-param array<Configuration> $configurations
	 * @psalm-param   array<Configuration> $configurations
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function isManagedByConfiguration(array $configurations): bool {
		return $this->getManagedByConfiguration(configurations: $configurations) !== null;
	}//end isManagedByConfiguration()

	/**
	 * Get the first configuration that has this entity's id among its members, or null.
	 *
	 * @param array<Configuration> $configurations Array of Configuration entities to check against
	 *
	 * @return Configuration|null The configuration managing this entity, or null
	 *
	 * @phpstan-param array<Configuration> $configurations
	 * @psalm-param   array<Configuration> $configurations
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function getManagedByConfiguration(array $configurations): ?Configuration {
		if ($this->id === null) {
			return null;
		}

		foreach ($configurations as $configuration) {
			if (in_array($this->id, ($this->configurationMembers(configuration: $configuration) ?? []), true) === true) {
				return $configuration;
			}
		}

		return null;
	}//end getManagedByConfiguration()

	/**
	 * The ids a configuration lists for this entity's kind.
	 *
	 * @param Configuration $configuration The configuration.
	 *
	 * @return array|null
	 */
	abstract protected function configurationMembers(Configuration $configuration): ?array;
}//end trait
