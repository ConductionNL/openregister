<?php

/**
 * RegisterScopedSchemaResolvers: builds a register-scoped schema resolver over the mappers a caller already holds.
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

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * Builds a RegisterScopedSchemaResolver over the mappers a caller already holds.
 *
 * Callers outside the Service component construct the resolver from their
 * own mapper bundle rather than resolving it from the container; this keeps
 * that construction while they type against the interface.
 *
 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
 */
final class RegisterScopedSchemaResolvers {

	/**
	 * A resolver over the given mappers.
	 *
	 * @param RegisterMapperInterface $registerMapper Resolves the register ref (id, uuid, or slug).
	 * @param SchemaMapperInterface   $schemaMapper   Resolves the schema ref within the register's carried ids.
	 *
	 * @return RegisterScopedSchemaResolverInterface The resolver.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public static function over(
		RegisterMapperInterface $registerMapper,
		SchemaMapperInterface $schemaMapper
	): RegisterScopedSchemaResolverInterface {
		return new RegisterScopedSchemaResolver(registerMapper: $registerMapper, schemaMapper: $schemaMapper);
	}//end over()
}//end class
