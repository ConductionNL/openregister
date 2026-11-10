<?php

/**
 * ResolvesLinkedObjectTrait
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Trait;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Resolves the object an object link route names. The using controller provides `$this->objectService`.
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
 */
trait ResolvesLinkedObjectTrait
{
	/**
	 * Resolve an OR object from register/schema/id.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       Object id.
	 *
	 * @return ObjectEntity|null
	 *
	 * @throws DoesNotExistException When no such object exists; the caller answers 404.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function validateObject(string $register, string $schema, string $id): ?ObjectEntity {
		return $this->resolveLinkedObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end validateObject()

	/**
	 * Resolve an OR object from one object reference.
	 *
	 * @param ObjectRef $ref The register, schema and object id from the route.
	 *
	 * @return ObjectEntity|null
	 *
	 * @throws DoesNotExistException When no such object exists; the caller answers 404.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function resolveLinkedObject(ObjectRef $ref): ?ObjectEntity {
		$this->objectService->setRegister((string)$ref->register);
		$this->objectService->setSchema((string)$ref->schema);
		$this->objectService->setObject($ref->id);

		return $this->objectService->getObject();
	}//end resolveLinkedObject()
}//end trait
