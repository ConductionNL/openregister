<?php

/**
 * OpenRegister relations object lookup
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * Resolves the object a relation or integration route names, through the object service's RBAC boundary.
 *
 * Every step is the object service's own: the register and schema scope it,
 * and `setObject()` looks the object up, so an unknown or ambiguous identifier
 * throws from here exactly as it does on the object API.
 *
 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-1
 */
final class RelationsObjectLookup {
	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects The object service.
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objects,
	) {
	}//end __construct()

	/**
	 * Scope the object service to a register and a schema.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 *
	 * @return void
	 */
	public function scope(string $register, string $schema): void {
		$this->objects->setRegister($register);
		$this->objects->setSchema($schema);
	}//end scope()

	/**
	 * The object a route names, read in the caller's scope.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object's id, uuid or slug.
	 *
	 * @return ObjectEntity|null The object, or null when the read answers none.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException When a slug names more than one.
	 */
	public function find(string $register, string $schema, string $id): ?ObjectEntity {
		$this->scope(register: $register, schema: $schema);
		$this->objects->setObject($id);

		return $this->objects->getObject();
	}//end find()
}//end class
