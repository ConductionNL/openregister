<?php

/**
 * OpenRegister BulkRelationRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObjects
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

namespace OCA\OpenRegister\Service\Object\SaveObjects;

use OCA\OpenRegister\Service\Object\SaveObject\RelationScanLevel;

/**
 * One pass of the bulk relation scan over a level of object data, and where it is.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class BulkRelationRun extends RelationScanLevel {

	/**
	 * The dot path of the array item being scanned.
	 *
	 * @var string
	 */
	public string $itemPath = '';

	/**
	 * Whether the schema declares the property as an array of objects.
	 *
	 * @var boolean
	 */
	public bool $itemsAreObjects = false;
}//end class
