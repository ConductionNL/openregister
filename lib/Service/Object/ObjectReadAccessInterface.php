<?php

/**
 * The read gate the relation endpoints check before they answer.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The read gate the relation endpoints check before they answer.
 *
 * Bound to ObjectReadAccess in ContractBindings. Every method is described on ObjectReadAccess.
 */
interface ObjectReadAccessInterface {

	/**
	 * The object behind a path, when the caller may read it.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 * @param string $id The object's uuid.
	 *
	 * @return ObjectEntity|null The object, or null when it is not readable.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function readable(string $register, string $schema, string $id): ?ObjectEntity;

	/**
	 * The schema an object belongs to, when it resolves.
	 *
	 * Returning null on an unresolvable schema is deliberate and safe: the
	 * right service treats a schema it cannot read as a refusal, because an
	 * unreadable rule refuses. Swallowing the failure into an allow is the
	 * fail-open this verb exists to prevent.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return Schema|null The schema.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md#requirement-export-is-its-own-permission-verb-req-exp-001
	 */
	public function schemaOf(ObjectEntity $object): ?Schema;

	/**
	 * The schema with this id, under the caller's own RBAC, or null when it does not resolve.
	 *
	 * @param string|int|null $schemaId The schema id.
	 *
	 * @return Schema|null The schema.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	public function schemaById(string|int|null $schemaId): ?Schema;

	/**
	 * The register an object belongs to, as an id, for the audit entry.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return int|null The register id.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function registerIdOf(ObjectEntity $object): ?int;

	/**
	 * The one answer a caller who may not read the object gets.
	 *
	 * 404 rather than 403, so the route does not confirm that an object with
	 * that uuid exists to somebody who may not see it.
	 *
	 * @return JSONResponse The refusal.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function notReadable(): JSONResponse;
}//end interface
