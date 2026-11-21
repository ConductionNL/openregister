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

use OCA\OpenRegister\Kernel\Contracts\ConfigurationMembershipInterface;

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
	 * @param array<ConfigurationMembershipInterface> $configurations The configurations to check against
	 *
	 * @return bool True if this entity is managed by at least one configuration
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function isManagedByConfiguration(array $configurations): bool {
		return $this->getManagedByConfiguration(configurations: $configurations) !== null;
	}//end isManagedByConfiguration()

	/**
	 * Get the first configuration that has this entity's id among its members, or null.
	 *
	 * @param array<T> $configurations The configurations to check against
	 *
	 * @return T|null The configuration managing this entity, or null
	 *
	 * @template T of ConfigurationMembershipInterface
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function getManagedByConfiguration(array $configurations): ?ConfigurationMembershipInterface {
		if ($this->id === null) {
			return null;
		}

		foreach ($configurations as $configuration) {
			if ($configuration->listsMember(kind: $this->configurationMemberKind(), id: $this->id) === true) {
				return $configuration;
			}
		}

		return null;
	}//end getManagedByConfiguration()

	/**
	 * The member list of a configuration that holds this entity's kind.
	 *
	 * @return string One of the kinds ConfigurationMembershipInterface::listsMember() reads.
	 */
	abstract protected function configurationMemberKind(): string;
}//end trait
