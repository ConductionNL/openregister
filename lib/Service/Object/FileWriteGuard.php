<?php

/**
 * The file half of the archive and freeze: a write to a frozen or archived
 * object's files is refused.
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
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ObjectStateWriteException;

/**
 * Refuses a file write on a frozen or archived object.
 *
 * The data half already refuses in `SaveObject`. Without this, a frozen
 * object's data was fixed while its attachments could still be replaced,
 * renamed or deleted, so a frozen record (a withdrawn publication, a
 * delivered Woo set) could still change under the same name. One class
 * decides, and both doors call it: the files API through
 * `FilesController::ensureObjectAccess()` and Nextcloud Files or WebDAV through
 * `FrozenNodeWriteListener`.
 *
 * Archived is asked before frozen, the same order `SaveObject` uses, so an
 * object that is both refuses in the same words through every door.
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
 */
class FileWriteGuard {

	/**
	 * Refuse when the object is archived or frozen.
	 *
	 * @param ObjectEntity $object The object that owns the files.
	 *
	 * @return void
	 *
	 * @throws ObjectStateWriteException When the object is archived or frozen.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
	 */
	public function assertWritable(ObjectEntity $object): void {
		$refusal = $this->refusalFor(object: $object);
		if ($refusal !== null) {
			throw $refusal;
		}
	}//end assertWritable()

	/**
	 * The refusal for this object, or null when its files may change.
	 *
	 * @param ObjectEntity $object The object that owns the files.
	 *
	 * @return ObjectStateWriteException|null The refusal naming the state, or null.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) archived() and frozen() are the exception's named constructors, as in SaveObject.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
	 */
	public function refusalFor(ObjectEntity $object): ?ObjectStateWriteException {
		if ($object->isArchived() === true) {
			return ObjectStateWriteException::archived($object);
		}

		if ($object->isFrozen() === true) {
			return ObjectStateWriteException::frozen($object);
		}

		return null;
	}//end refusalFor()
}//end class
