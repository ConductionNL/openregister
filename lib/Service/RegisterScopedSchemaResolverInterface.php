<?php

/**
 * RegisterScopedSchemaResolverInterface: what callers use of RegisterScopedSchemaResolver.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;

/**
 * Resolves a schema identifier inside the boundary of a named register.
 */
interface RegisterScopedSchemaResolverInterface {

	/**
	 * Resolve a register reference (id, uuid or slug) without falling back.
	 *
	 * @param int|string $registerRef The register id, uuid, or slug.
	 *
	 * @return Register The resolved register.
	 *
	 * @throws RegisterNotFoundException When the named register does not resolve.
	 */
	public function resolveRegister(int|string $registerRef): Register;

	/**
	 * Resolve a schema identifier among the schemas an already-resolved register carries.
	 *
	 * @param Register   $register  The register that bounds the resolution.
	 * @param int|string $schemaRef The schema id, uuid, or slug.
	 *
	 * @return Schema The schema, resolved among the register's carried schemas.
	 *
	 * @throws SchemaNotInRegisterException When the register does not carry the identifier.
	 */
	public function resolveSchemaWithin(Register $register, int|string $schemaRef): Schema;

	/**
	 * Resolve a register and then a schema within it.
	 *
	 * @param int|string $registerRef The register id, uuid, or slug.
	 * @param int|string $schemaRef   The schema id, uuid, or slug.
	 *
	 * @return array{register: Register, schema: Schema} The resolved pair.
	 *
	 * @throws RegisterNotFoundException    When the named register does not resolve.
	 * @throws SchemaNotInRegisterException When the register does not carry the schema.
	 */
	public function resolvePair(int|string $registerRef, int|string $schemaRef): array;
}//end interface
