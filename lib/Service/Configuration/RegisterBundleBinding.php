<?php

/**
 * OpenRegister RegisterBundleBinding
 *
 * What a register manager who is not an administrator may bring in through a
 * configuration bundle uploaded to that register: its own schemas, and no
 * crossing those schemas did not already hold.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-a-bundle-uploaded-by-a-register-manager-stays-inside-that-register
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Rbac\CrossOrganisationMarkerChange;

/**
 * Judges a bundle against the register it was uploaded to.
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-a-bundle-uploaded-by-a-register-manager-stays-inside-that-register
 */
class RegisterBundleBinding implements RegisterBundleBindingInterface {

	/**
	 * The bundle parts a register manager may bring in.
	 */
	private const ALLOWED_COMPONENTS = ['registers', 'schemas'];

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemaMapper Reads the schemas of the register.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()

	/**
	 * Why this bundle may not be imported into this register by a manager.
	 *
	 * @param Register             $register The register the bundle was uploaded to.
	 * @param array<string, mixed> $bundle   The bundle.
	 *
	 * @return string|null The reason, or null when the bundle stays inside the register.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-a-bundle-uploaded-by-a-register-manager-stays-inside-that-register
	 */
	public function refusalFor(Register $register, array $bundle): ?string {
		$components = ($bundle['components'] ?? []);
		if (is_array($components) === false || array_diff(array_keys($components), self::ALLOWED_COMPONENTS) !== []) {
			return 'A register manager may only import schemas and the register itself';
		}

		$own = $this->schemasBySlug(register: $register);

		return $this->registerRefusal(register: $register, components: $components, own: $own)
			?? $this->schemaRefusal(schemas: ($components['schemas'] ?? []), own: $own);
	}//end refusalFor()

	/**
	 * The stored schemas of the register, by slug.
	 *
	 * @param Register $register The register.
	 *
	 * @return array<string, Schema>
	 */
	private function schemasBySlug(Register $register): array {
		$bySlug = [];
		foreach ($this->schemaMapper->findMultiple(ids: ($register->getSchemas() ?? [])) as $schema) {
			$bySlug[(string)$schema->getSlug()] = $schema;
		}

		return $bySlug;
	}//end schemasBySlug()

	/**
	 * Why the bundle's registers part may not be imported.
	 *
	 * @param Register              $register   The register the bundle was uploaded to.
	 * @param array<string, mixed>  $components The bundle's components.
	 * @param array<string, Schema> $own        The stored schemas of the register.
	 *
	 * @return string|null The reason, or null.
	 */
	private function registerRefusal(Register $register, array $components, array $own): ?string {
		$registers = ($components['registers'] ?? []);
		if (is_array($registers) === false) {
			return 'The bundle has an unreadable registers part';
		}

		foreach ($registers as $key => $entry) {
			$slug  = ($entry['slug'] ?? $key);
			$named = ($entry['schemas'] ?? []);
			if ($slug !== $register->getSlug() || $key !== $register->getSlug() || is_array($named) === false) {
				return 'A register manager may not import another register';
			}

			if (array_diff($named, array_keys($own)) !== []) {
				return 'A register manager may not link another schema to this register';
			}
		}

		return null;
	}//end registerRefusal()

	/**
	 * Why the bundle's schemas part may not be imported.
	 *
	 * @param mixed                 $schemas The bundle's schemas part.
	 * @param array<string, Schema> $own     The stored schemas of the register.
	 *
	 * @return string|null The reason, or null.
	 */
	private function schemaRefusal(mixed $schemas, array $own): ?string {
		if (is_array($schemas) === false) {
			return 'The bundle has an unreadable schemas part';
		}

		$change = new CrossOrganisationMarkerChange();
		foreach ($schemas as $key => $document) {
			$slug = ($document['slug'] ?? $key);
			if (is_array($document) === false || isset($own[$key]) === false || $slug !== $key) {
				return 'A register manager may only import schemas of this register';
			}

			if ($change->widens(old: $own[$key]->getAuthorization(), new: ($document['authorization'] ?? null)) === true) {
				return 'Only an administrator may let a rule read across organisations (crossOrganisation: true)';
			}
		}

		return null;
	}//end schemaRefusal()
}//end class
