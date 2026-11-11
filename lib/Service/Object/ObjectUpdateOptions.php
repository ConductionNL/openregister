<?php

/**
 * OpenRegister ObjectUpdateOptions
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

/**
 * The optional parts of a stored-object update: silent mode and the object as it was before.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class ObjectUpdateOptions {

	/**
	 * Constructor.
	 *
	 * @param bool              $silent    Whether to skip audit trail creation, events and inverse relations.
	 * @param ObjectEntity|null $oldObject The object before the changes, or null to snapshot the stored row.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly bool $silent = false,
		public readonly ?ObjectEntity $oldObject = null,
	) {
	}//end __construct()
}//end class
