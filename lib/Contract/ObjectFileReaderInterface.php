<?php

/**
 * ObjectFileReaderInterface: the published read of the files attached to an object.
 *
 * An object's files live in a folder OpenRegister owns. A consuming app that
 * needs them (an archive of all its data, for one) cannot find that folder
 * without reaching into this app's file handling, so the read is published
 * here, next to {@see ObjectServiceInterface}, and owned by the app that
 * implements it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

/**
 * Reads the files attached to an object.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
interface ObjectFileReaderInterface {

	/**
	 * The files attached to an object, as Nextcloud file nodes.
	 *
	 * The caller checks that its user may see the object before it asks: the
	 * read itself is by object uuid and applies no object rights. An object
	 * that never had a files folder has no files and answers an empty list.
	 * Folders inside the object's folder are left out; only files are listed.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return array<int,\OCP\Files\File> The files, in the folder's order.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no object has that uuid.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getObjectFiles(string $objectUuid): array;
}//end interface
