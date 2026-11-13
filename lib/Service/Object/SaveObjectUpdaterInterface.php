<?php

/**
 * SaveObjectUpdaterInterface: the stored-object update of SaveObject.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/history-revert-through-the-save-path/specs/content-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The update role of SaveObjectInterface, for callers that write a new state
 * of a stored object through the save path (a revert).
 *
 * @spec openspec/changes/history-revert-through-the-save-path/specs/content-versioning/spec.md
 */
interface SaveObjectUpdaterInterface {

	/**
	 * Updates an existing object.
	 *
	 * @param StoredObjectUpdate  $update  The register, schema, new data and the stored object to update.
	 * @param ObjectUpdateOptions $options Silent mode, the original object before changes, and the
	 *                                     origin and markers the audit row records.
	 *
	 * @return ObjectEntity The updated object entity.
	 *
	 * @throws Exception If there is an error during update.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function updateObject(
		StoredObjectUpdate $update,
		ObjectUpdateOptions $options = new ObjectUpdateOptions(),
	): ObjectEntity;
}//end interface
