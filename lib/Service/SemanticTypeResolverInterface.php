<?php

/**
 * SemanticTypeResolverInterface: the part of SemanticTypeResolver that the schemas controller uses.
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

/**
 * The methods of SemanticTypeResolver that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface SemanticTypeResolverInterface {

	/**
	 * Resolve a semantic-type URI to the schema that implements it.
	 *
	 * Enumerates all schemas the caller can see, keeps those whose implemented
	 * types contain `$uri`, and returns the deterministic pick — or `null` when
	 * none implement it. Never raises for a "not found" outcome.
	 *
	 * Tie-break when >1 candidate matches: (a) a schema in the consuming
	 * register (when known), else (b) the first candidate by slug. A WARN log
	 * records the ambiguity and the chosen provider.
	 *
	 * @param string $uri The canonical semantic-type URI (absolute IRI).
	 * @param int|null $consumingRegisterId The consuming schema's register id, used only to bias the tie-break.
	 *
	 * @return Schema|null The implementing schema, or null when none is installed.
	 *
	 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
	 *   (Requirement: Resolution is null-safe across installed schemas)
	 */
	public function resolveSchemaByImplements(string $uri, ?int $consumingRegisterId = null): ?Schema;

	/**
	 * Find the register a resolved schema belongs to.
	 *
	 * Registers hold their schema ids in `Register::getSchemas()`; a schema
	 * carries no back-reference, so we scan registers for membership. Returns
	 * the first register whose schema-id list contains the schema id. Null when
	 * the schema is orphaned (belongs to no register) or enumeration fails.
	 *
	 * @param Schema $schema The resolved schema.
	 *
	 * @return Register|null The owning register, or null when none is found.
	 *
	 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
	 *   (Requirement: Resolution is null-safe across installed schemas)
	 */
	public function findRegisterForSchema(Schema $schema): ?Register;
}//end interface
