<?php

/**
 * A schema reference resolved inside the register the caller named.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-scoped-schema-slug-resolution/specs/schema-slug-resolution/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use Throwable;

/**
 * Resolve a schema id, uuid or slug with the register in context.
 *
 * Slugs are unique within a register, not across the instance, so a slug
 * resolved globally can name another app's schema. This is the pattern
 * `ObjectService::setSchema()` established: a slug is matched first among the
 * register's own schemas ({@see SchemaMapperInterface::findBySlugInIds()}),
 * and only when the register does not carry it, or there is no register, does
 * the global {@see SchemaMapperInterface::find()} answer. Numeric ids and
 * uuids go straight to `find()`, unchanged.
 *
 * @spec openspec/changes/register-scoped-schema-slug-resolution/specs/schema-slug-resolution/spec.md
 */
final class SchemaRefInRegister {

	/**
	 * Create the lookup.
	 *
	 * @param SchemaMapperInterface        $schemas   The schema mapper.
	 * @param RegisterMapperInterface|null $registers Reads a register named by id, uuid or slug; absent means only a
	 *                                                Register entity scopes the lookup.
	 *
	 * @spec openspec/changes/register-scoped-schema-slug-resolution/specs/schema-slug-resolution/spec.md
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly ?RegisterMapperInterface $registers = null,
	) {
	}//end __construct()

	/**
	 * The schema a reference names, looked up inside the register first.
	 *
	 * @param int|string $schemaRef The schema's id, uuid or slug.
	 * @param mixed      $register  The register in context: the entity, its id, uuid or slug; anything else scopes nothing.
	 * @param bool       $_rbac          Passed to the global lookups, as the caller passed it to find() before.
	 * @param bool       $_multitenancy  Passed to the global lookups, as the caller passed it to find() before.
	 *
	 * @return Schema The schema.
	 *
	 * @throws DoesNotExistException When the global lookup finds nothing either.
	 *
	 * @spec openspec/changes/register-scoped-schema-slug-resolution/specs/schema-slug-resolution/spec.md
	 */
	public function find(int|string $schemaRef, mixed $register, bool $_rbac = true, bool $_multitenancy = true): Schema {
		$scoped = $this->inRegister(schemaRef: $schemaRef, register: $register, flags: [$_rbac, $_multitenancy]);
		if ($scoped !== null) {
			return $scoped;
		}

		return $this->schemas->find($schemaRef, _rbac: $_rbac, _multitenancy: $_multitenancy);
	}//end find()

	/**
	 * The register's own schema carrying this slug, or null.
	 *
	 * @param int|string $schemaRef The schema reference.
	 * @param mixed      $register  The register in context.
	 * @param bool[]     $flags     The caller's rbac and multitenancy flags, for reading a register reference.
	 *
	 * @return Schema|null The scoped match; null for an id, a uuid, no register or a slug the register lacks.
	 */
	private function inRegister(int|string $schemaRef, mixed $register, array $flags): ?Schema {
		if (is_string($schemaRef) === false || $schemaRef === '' || is_numeric($schemaRef) === true) {
			return null;
		}

		$entity = $this->registerOf(register: $register, flags: $flags);
		if ($entity === null) {
			return null;
		}

		return $this->schemas->findBySlugInIds(slug: $schemaRef, schemaIds: ($entity->getSchemas() ?? []));
	}//end inRegister()

	/**
	 * The register entity, read when only a reference was given.
	 *
	 * An unreadable register scopes nothing: the lookup then answers exactly
	 * as it did before this change, rather than failing a working request.
	 *
	 * @param mixed  $register The register in context.
	 * @param bool[] $flags    The caller's rbac and multitenancy flags.
	 *
	 * @return Register|null The entity, or null.
	 */
	private function registerOf(mixed $register, array $flags): ?Register {
		if ($register instanceof Register) {
			return $register;
		}

		if ((is_int($register) === false && is_string($register) === false) || $register === '' || $this->registers === null) {
			return null;
		}

		try {
			return $this->registers->find($register, _rbac: $flags[0], _multitenancy: $flags[1]);
		} catch (Throwable $e) {
			return null;
		}
	}//end registerOf()
}//end class
