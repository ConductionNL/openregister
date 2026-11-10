<?php

/**
 * OpenRegister ObjectUpdate
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
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;

/**
 * One full replace: the target, the submitted object and the caller.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectUpdate {
	/**
	 * Hold the update.
	 *
	 * @param ObjectTarget         $target The object aimed at.
	 * @param array<string, mixed> $object What may be saved.
	 * @param WriteCaller          $caller The caller's scope and uploads.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly ObjectTarget $target,
		public readonly array $object,
		public readonly WriteCaller $caller,
	) {
	}//end __construct()

	/**
	 * Save the object under a uuid.
	 *
	 * @param string $uuid The uuid the caller's read resolved.
	 *
	 * @return ObjectEntity The saved object.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function saved(string $uuid): ObjectEntity {
		return $this->target->objectService->saveObject(
			object: $this->object,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $this->target->resolved['register'], schema: $this->target->resolved['schema']),
				uuid: $uuid,
				uploadedFiles: $this->caller->uploads()
			),
			_rbac: $this->caller->scoped,
			_multitenancy: $this->caller->scoped
		);
	}//end saved()
}//end class
