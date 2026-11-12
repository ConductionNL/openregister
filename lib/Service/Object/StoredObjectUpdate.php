<?php

/**
 * OpenRegister StoredObjectUpdate
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * One update of a stored object: where it lives, the new data and the stored row it replaces.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class StoredObjectUpdate {

	/**
	 * Constructor.
	 *
	 * @param Register|int|string $register       The register containing the object, or its id.
	 * @param Schema|int|string   $schema         The schema to validate against, or its id.
	 * @param array               $data           The updated object data.
	 * @param ObjectEntity        $existingObject The stored object to update.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly Register|int|string $register,
		public readonly Schema|int|string $schema,
		public readonly array $data,
		public readonly ObjectEntity $existingObject,
	) {
	}//end __construct()
}//end class
