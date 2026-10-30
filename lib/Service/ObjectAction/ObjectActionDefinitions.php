<?php

/**
 * The declared actions that apply to one schema, register defaults included.
 *
 * A register's `configuration['x-openregister-action']` holds entries every
 * schema in the register inherits. A schema entry with the same key replaces
 * the inherited one entirely, with no deep merge, so what runs is always one
 * readable entry. Inheritance is resolved here, at read time, and never copied
 * into a schema, so changing the register changes every schema at once.
 *
 * 🔴 AN INHERITED ENTRY NEVER ELEVATES. A register save does not pass through
 * the schema validator that refuses wildcards and non-admin savers, so an
 * `elevate` list on a register-level entry is dropped here rather than trusted.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-register-may-declare-actions-its-schemas-inherit
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\RegisterMapperInterface;
use Throwable;

/**
 * Resolves a schema's declared actions, inheriting the register's.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ObjectActionDefinition's readers are stateless declaration readers.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-register-may-declare-actions-its-schemas-inherit
 */
class ObjectActionDefinitions {

	/**
	 * Constructor.
	 *
	 * @param RegisterMapperInterface<Register, Schema> $registers Loads the register an object lives in.
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registers,
	) {
	}//end __construct()

	/**
	 * The well-formed actions of a schema, inherited ones included.
	 *
	 * @param Schema                   $schema   The schema.
	 * @param Register|int|string|null $register The register, or its id, or null when unknown.
	 *
	 * @return array<string, ObjectActionDefinition> Action key => definition.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-register-may-declare-actions-its-schemas-inherit
	 */
	public function forSchema(Schema $schema, Register|int|string|null $register = null): array {
		return self::resolve(
			schemaConfig: ($schema->getConfiguration() ?? []),
			registerConfig: $this->registerConfiguration(register: $register)
		);
	}//end forSchema()

	/**
	 * One action of a schema, or null when it declares or inherits none by that key.
	 *
	 * @param Schema                   $schema   The schema.
	 * @param string                   $key      The action key.
	 * @param Register|int|string|null $register The register, or its id.
	 *
	 * @return ObjectActionDefinition|null The definition.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-register-may-declare-actions-its-schemas-inherit
	 */
	public function find(Schema $schema, string $key, Register|int|string|null $register = null): ?ObjectActionDefinition {
		return ($this->forSchema(schema: $schema, register: $register)[$key] ?? null);
	}//end find()

	/**
	 * Merge register defaults under a schema's own declarations.
	 *
	 * @param array<string, mixed> $schemaConfig   The schema configuration.
	 * @param array<string, mixed> $registerConfig The register configuration.
	 *
	 * @return array<string, ObjectActionDefinition> Action key => definition.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-register-may-declare-actions-its-schemas-inherit
	 */
	public static function resolve(array $schemaConfig, array $registerConfig): array {
		$lifecycle = ($schemaConfig['x-openregister-lifecycle'] ?? []);
		if (is_array($lifecycle) === false) {
			$lifecycle = [];
		}

		$own = ObjectActionDefinition::parse(configuration: $schemaConfig, lifecycle: $lifecycle);
		$declaredByName = ObjectActionDefinition::declarations(configuration: $schemaConfig);

		$resolved = [];
		foreach (ObjectActionDefinition::parse(configuration: $registerConfig, lifecycle: $lifecycle) as $key => $inherited) {
			if (array_key_exists($key, $declaredByName) === true) {
				continue;
			}

			$resolved[$key] = $inherited->withoutElevation();
		}

		return array_merge($resolved, $own);
	}//end resolve()

	/**
	 * The register's configuration, or [] when there is none to read.
	 *
	 * @param Register|int|string|null $register The register, or its id.
	 *
	 * @return array<string, mixed> The configuration.
	 */
	private function registerConfiguration(Register|int|string|null $register): array {
		if ($register === null || $register === '') {
			return [];
		}

		if ($register instanceof Register === false) {
			try {
				$register = $this->registers->find($register, _multitenancy: false, _rbac: false);
			} catch (Throwable) {
				return [];
			}
		}

		return ($register->getConfiguration() ?? []);
	}//end registerConfiguration()
}//end class
