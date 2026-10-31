<?php

/**
 * The schema facts a notification rule is validated against.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

/**
 * Declared properties and assigned role names of one schema.
 */
final class NotificationSchemaFacts {

	/**
	 * The property definitions, empty when the schema has none.
	 *
	 * @var array<int|string, mixed>
	 */
	public readonly array $properties;

	/**
	 * The declared property names.
	 *
	 * @var array<int, int|string>
	 */
	public readonly array $propKeys;

	/**
	 * The role names `authorization.roles` assigns.
	 *
	 * When the schema assigns no roles the list is empty and role checks
	 * stand down, because the assignment may live on a register the
	 * validator is not given.
	 *
	 * @var array<int, string>
	 */
	public readonly array $declaredRoles;

	/**
	 * Read the facts from a schema definition.
	 *
	 * @param array<string, mixed> $schema The schema definition.
	 */
	public function __construct(array $schema) {
		$properties = ($schema['properties'] ?? []);
		if (is_array($properties) === false) {
			$properties = [];
		}

		$declaredRoles = [];
		$authorization = ($schema['authorization'] ?? null);
		if (is_array($authorization) === true && is_array(($authorization['roles'] ?? null)) === true) {
			$declaredRoles = array_values(array_filter(array_keys($authorization['roles']), 'is_string'));
		}

		$this->properties    = $properties;
		$this->propKeys      = array_keys($properties);
		$this->declaredRoles = $declaredRoles;
	}//end __construct()
}//end class
