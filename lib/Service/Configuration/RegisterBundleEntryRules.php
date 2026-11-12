<?php

/**
 * OpenRegister RegisterBundleEntryRules
 *
 * The per-entry rules of {@see RegisterBundleBinding}: for the register a
 * bundle was uploaded to and its stored schemas, why one entry of the
 * bundle's registers or schemas part may not be imported by a register
 * manager.
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
use OCA\OpenRegister\Service\Rbac\CrossOrganisationMarkerChange;

/**
 * One register and its stored schemas, judging bundle entries one at a time.
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-a-bundle-uploaded-by-a-register-manager-stays-inside-that-register
 */
final readonly class RegisterBundleEntryRules {

	/**
	 * Constructor.
	 *
	 * @param Register              $register The register the bundle was uploaded to.
	 * @param array<string, Schema> $own      The stored schemas of the register, by slug.
	 */
	public function __construct(
		public Register $register,
		public array $own,
	) {
	}//end __construct()

	/**
	 * Why one entry of the bundle's registers part may not be imported.
	 *
	 * @param int|string $key   The entry's key in the registers part.
	 * @param mixed      $entry The entry.
	 *
	 * @return string|null The reason, or null.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-a-bundle-uploaded-by-a-register-manager-stays-inside-that-register
	 */
	public function registerEntryRefusal(int|string $key, mixed $entry): string|null {
		$slug  = ($entry['slug'] ?? $key);
		$named = ($entry['schemas'] ?? []);
		if ($slug !== $this->register->getSlug() || $key !== $this->register->getSlug() || is_array($named) === false) {
			return 'A register manager may not import another register';
		}

		if (array_diff($named, array_keys($this->own)) !== []) {
			return 'A register manager may not link another schema to this register';
		}

		return null;
	}//end registerEntryRefusal()

	/**
	 * Why one entry of the bundle's schemas part may not be imported.
	 *
	 * @param int|string $key      The entry's key in the schemas part.
	 * @param mixed      $document The schema document.
	 *
	 * @return string|null The reason, or null.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-a-bundle-uploaded-by-a-register-manager-stays-inside-that-register
	 */
	public function schemaEntryRefusal(int|string $key, mixed $document): string|null {
		$slug = ($document['slug'] ?? $key);
		if (is_array($document) === false || isset($this->own[$key]) === false || $slug !== $key) {
			return 'A register manager may only import schemas of this register';
		}

		$change = new CrossOrganisationMarkerChange();
		if ($change->widens(old: $this->own[$key]->getAuthorization(), new: ($document['authorization'] ?? null)) === true) {
			return 'Only an administrator may let a rule read across organisations (crossOrganisation: true)';
		}

		return null;
	}//end schemaEntryRefusal()
}//end class
