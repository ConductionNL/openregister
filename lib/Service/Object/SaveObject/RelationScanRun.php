<?php

/**
 * OpenRegister RelationScanRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
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

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Schema;

/**
 * One SaveObject::scanForRelations() pass over a level of object data, and the key it is on.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RelationScanRun {

	/**
	 * The schema the data is scanned against, if any.
	 *
	 * @var Schema|null
	 */
	public ?Schema $schema = null;

	/**
	 * The schema's property definitions, or null without a readable schema.
	 *
	 * @var array|null
	 */
	public ?array $schemaProperties = null;

	/**
	 * The relations found so far, by dot path.
	 *
	 * @var array
	 */
	public array $relations = [];

	/**
	 * The data key being scanned.
	 *
	 * @var string
	 */
	public string $key = '';

	/**
	 * The dot path of the key being scanned.
	 *
	 * @var string
	 */
	public string $path = '';
}//end class
