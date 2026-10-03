<?php

/**
 * Whether this caller may read one object, and what it belongs to.
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
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUserSession;

/**
 * Resolves an object under the caller's own RBAC, and refuses with a 404.
 *
 * 🔴 THE REFUSAL IS A 404, NOT A 403, and that is the whole reason it is one
 * method rather than a literal at each call site: a 403 would confirm to
 * somebody who may not see an object that an object with that uuid exists.
 * Every endpoint that resolves an object this way has to answer the same
 * way, and a single `notReadable()` is what makes that checkable.
 *
 * 🔑 REGISTER BEFORE SCHEMA. `ObjectService::setSchema()` resolves its slug
 * inside whatever register is currently set, and the service is reused
 * across calls in one process, so the order is load-bearing rather than
 * stylistic.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
class ObjectReadAccess {

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads objects, with RBAC.
	 * @param IUserSession  $userSession   Current-user session.
	 * @param SchemaMapper  $schemaMapper  Resolves the object's schema, whose rule carries the verb.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly SchemaMapper $schemaMapper,
	) {
	}//end __construct()

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
	public function readable(string $register, string $schema, string $id): ?ObjectEntity {
		if ($this->userSession->getUser() === null) {
			return null;
		}

		try {
			// REGISTER FIRST: setSchema() resolves its slug inside whatever
			// register is currently set, and ObjectService is reused across
			// calls in one process.
			$this->objectService->setRegister(register: $register);
			$this->objectService->setSchema(schema: $schema);

			return $this->objectService->find(
				id: $id,
				register: $register,
				schema: $schema,
				_rbac: true,
				_multitenancy: true
			);
		} catch (\Exception $e) {
			return null;
		}
	}//end readable()

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
	 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md#requirement-export-is-its-own-permission-verb-req-exp-001
	 */
	public function schemaOf(ObjectEntity $object): ?Schema {
		try {
			return $this->schemaMapper->find($object->getSchema());
		} catch (\Throwable $exception) {
			return null;
		}
	}//end schemaOf()

	/**
	 * The register an object belongs to, as an id, for the audit entry.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return int|null The register id.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function registerIdOf(ObjectEntity $object): ?int {
		$register = $object->getRegister();

		if (is_numeric($register) === false) {
			return null;
		}

		return (int)$register;
	}//end registerIdOf()

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
	public function notReadable(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
	}//end notReadable()

}//end class
