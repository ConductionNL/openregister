<?php

/**
 * The object a managed file belongs to, telling "no object owns it" from "the lookup failed".
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\MagicMapperInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\Node;

/**
 * Finds the object whose folder a file sits in.
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
 */
class ManagedFileOwnerLookup {

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface<ObjectEntity, Register, Schema> $objects Finds an object by the name of its folder.
	 */
	public function __construct(
		private readonly MagicMapperInterface $objects,
	) {
	}//end __construct()

	/**
	 * The object a managed file belongs to, throwing when the lookup itself fails.
	 *
	 * The file's parent is the object folder, named after the object's uuid, and the object records that folder's
	 * id; both must agree. Null is only "no object owns this file" (no object of the folder's name, or one whose
	 * recorded folder is another); a database or filesystem failure propagates, so a caller that fails closed can
	 * tell the two apart.
	 *
	 * @param Node $file The file.
	 *
	 * @return ObjectEntity|null The object, or null when no object owns the file.
	 *
	 * @throws \Throwable When the file's folder or the object lookup fails.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
	 */
	public function find(Node $file): ?ObjectEntity {
		$parent = $file->getParent();
		try {
			$found = $this->objects->findAcrossAllSources(
				identifier: $parent->getName(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $e) {
			return null;
		}

		$object = ($found['object'] ?? null);
		if ($object instanceof ObjectEntity === false) {
			return null;
		}

		if ((string)$object->getFolder() !== (string)$parent->getId()) {
			return null;
		}

		return $object;
	}//end find()
}//end class
