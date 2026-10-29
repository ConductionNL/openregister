<?php

/**
 * One listed seed object's target and import context.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/seed-objects-follow-the-shipped-version/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IUser;

/**
 * The register, schema and slug a listed object is imported into, the app
 * importing it and the user the save acts as.
 *
 * @spec openspec/changes/seed-objects-follow-the-shipped-version/specs/data-import-export/spec.md
 */
final class ListedSeed
{

	/**
	 * Constructor.
	 *
	 * @param Register   $register   The register the object lives in.
	 * @param Schema     $schema     The object's schema.
	 * @param mixed      $slug       The object's `@self` slug.
	 * @param string|null $appId      The importing app.
	 * @param IUser|null $actingUser The user the save acts as.
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly mixed $slug,
		public readonly ?string $appId,
		public readonly ?IUser $actingUser,
	) {
	}//end __construct()

	/**
	 * The key the import remembers this seed's shipped version and content under.
	 *
	 * @return string|null `<registerId>/<schemaId>/<slug>`, or null for an import without an app.
	 *
	 * @spec openspec/changes/seed-objects-follow-the-shipped-version/specs/data-import-export/spec.md
	 */
	public function seedKey(): ?string {
		if ($this->appId === null || $this->appId === '') {
			return null;
		}

		return (int)$this->register->getId().'/'.(int)$this->schema->getId().'/'.$this->slug;
	}//end seedKey()
}//end class
