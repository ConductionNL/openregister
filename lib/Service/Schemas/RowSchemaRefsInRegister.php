<?php

/**
 * OpenRegister RowSchemaRefsInRegister
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/register-scoped-schema-slug-resolution/specs/schema-slug-resolution/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * Pins the schema slug each row of a mixed-schema batch names to the batch register's own schema.
 *
 * A mixed-schema batch names its schema per row under `@self.schema`. The rights
 * check and the save each resolved that slug on their own, globally, so a slug two
 * registers share could name one schema to the check and another to the write.
 * This rewrites the slug to the id of the register's own schema once, before both
 * run, so they read the same schema. A row naming an id, a uuid, a slug the
 * register does not carry, or another register than the batch's, is left as it is
 * and resolves exactly as before.
 *
 * @spec openspec/changes/register-scoped-schema-slug-resolution/specs/schema-slug-resolution/spec.md
 */
final class RowSchemaRefsInRegister {

	/**
	 * Create the pass.
	 *
	 * @param SchemaRefInRegister $lookup The register-scoped slug lookup.
	 *
	 * @spec openspec/changes/register-scoped-schema-slug-resolution/specs/schema-slug-resolution/spec.md
	 */
	public function __construct(
		private readonly SchemaRefInRegister $lookup,
	) {
	}//end __construct()

	/**
	 * The rows, with every `@self.schema` slug the register carries replaced by that schema's id.
	 *
	 * @param array<mixed>    $objects  The rows.
	 * @param int|string|null $register The batch register's id; null or empty scopes nothing.
	 *
	 * @return array<mixed> The rows, keys and order kept.
	 *
	 * @spec openspec/changes/register-scoped-schema-slug-resolution/specs/schema-slug-resolution/spec.md
	 */
	public function scope(array $objects, int|string|null $register): array {
		if ($register === null || $register === '' || $register === 0 || $register === '0') {
			return $objects;
		}

		$resolved = [];
		foreach ($objects as $key => $object) {
			$slug = self::scopableSlug(object: $object, register: $register);
			if ($slug === null) {
				continue;
			}

			if (array_key_exists($slug, $resolved) === false) {
				$resolved[$slug] = $this->lookup->findInRegister(
					schemaRef: $slug,
					register: $register,
					_rbac: false,
					_multitenancy: false
				)?->getId();
			}

			if ($resolved[$slug] !== null) {
				$objects[$key]['@self']['schema'] = $resolved[$slug];
			}
		}

		return $objects;
	}//end scope()

	/**
	 * The slug a row names for its schema, when the row belongs to the batch register.
	 *
	 * @param mixed      $object   The row.
	 * @param int|string $register The batch register's id.
	 *
	 * @return string|null The slug; null for a row that is not a map, names no slug, or names another register.
	 */
	private static function scopableSlug(mixed $object, int|string $register): ?string {
		if (is_array($object) === false || is_array($object['@self'] ?? null) === false) {
			return null;
		}

		$schema = ($object['@self']['schema'] ?? null);
		if (is_string($schema) === false || $schema === '' || is_numeric($schema) === true) {
			return null;
		}

		$rowRegister = ($object['@self']['register'] ?? null);
		if ($rowRegister !== null && (is_scalar($rowRegister) === false || (string)$rowRegister !== (string)$register)) {
			return null;
		}

		return $schema;
	}//end scopableSlug()
}//end class
