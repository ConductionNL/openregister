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

		$rules = new RegisterBundleEntryRules(register: $register, own: $this->schemasBySlug(register: $register));

		return $this->registerRefusal(registers: ($components['registers'] ?? []), rules: $rules)
			?? $this->schemaRefusal(schemas: ($components['schemas'] ?? []), rules: $rules);
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
	 * @param mixed                    $registers The bundle's registers part.
	 * @param RegisterBundleEntryRules $rules     The register and its stored schemas.
	 *
	 * @return string|null The reason, or null.
	 */
	private function registerRefusal(mixed $registers, RegisterBundleEntryRules $rules): string|null {
		if (is_array($registers) === false) {
			return 'The bundle has an unreadable registers part';
		}

		foreach ($registers as $key => $entry) {
			$reason = $rules->registerEntryRefusal(key: $key, entry: $entry);
			if ($reason !== null) {
				return $reason;
			}
		}

		return null;
	}//end registerRefusal()

	/**
	 * Why the bundle's schemas part may not be imported.
	 *
	 * @param mixed                    $schemas The bundle's schemas part.
	 * @param RegisterBundleEntryRules $rules   The register and its stored schemas.
	 *
	 * @return string|null The reason, or null.
	 */
	private function schemaRefusal(mixed $schemas, RegisterBundleEntryRules $rules): string|null {
		if (is_array($schemas) === false) {
			return 'The bundle has an unreadable schemas part';
		}

		foreach ($schemas as $key => $document) {
			$reason = $rules->schemaEntryRefusal(key: $key, document: $document);
			if ($reason !== null) {
				return $reason;
			}
		}

		return null;
	}//end schemaRefusal()
}//end class
