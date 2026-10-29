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
	 * The object's `@self` slug.
	 *
	 * @var mixed
	 */
	public readonly mixed $slug;

	/**
	 * The importing app.
	 *
	 * @var string|null
	 */
	public readonly ?string $appId;

	/**
	 * The user the save acts as.
	 *
	 * @var IUser|null
	 */
	public readonly ?IUser $actingUser;

	/**
	 * Constructor.
	 *
	 * @param Register                                         $register The register the object lives in.
	 * @param Schema                                           $schema   The object's schema.
	 * @param array{slug: mixed, appId: ?string, user: ?IUser} $context  The slug, the importing app and the acting user.
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		array $context,
	) {
		$this->slug = $context['slug'];
		$this->appId = $context['appId'];
		$this->actingUser = $context['user'];
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
