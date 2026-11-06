<?php

/**
 * OpenRegister ObjectUpdateRun
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * One SaveObject::updateObject() call once its register and schema are resolved.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class ObjectUpdateRun {

	/**
	 * The register of the object, resolved to its entity.
	 *
	 * @var Register|null
	 */
	public ?Register $register = null;

	/**
	 * The schema of the object, resolved to its entity.
	 *
	 * @var Schema|null
	 */
	public ?Schema $schema = null;

	/**
	 * The updated object data, without `@self` and id; file handling rewrites it.
	 *
	 * @var array
	 */
	public array $data = [];

	/**
	 * The `@self` metadata of the update.
	 *
	 * @var array
	 */
	public array $selfData = [];

	/**
	 * The folder id to set on the object.
	 *
	 * @var integer|null
	 */
	public ?int $folderId = null;

	/**
	 * Whether audit trail, events and inverse relations are skipped.
	 *
	 * @var boolean
	 */
	public bool $silent = false;

	/**
	 * The object as it was before the update, for audit and events.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $oldObject = null;

	/**
	 * The object as updated, once it is persisted.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $updated = null;
}//end class
