<?php

/**
 * OpenRegister ObjectSaveCall
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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The state of one SaveObject::saveObject() call once its register and schema are resolved.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class ObjectSaveCall {

	/**
	 * The register the object is saved in, once resolved.
	 *
	 * @var Register|null
	 */
	public ?Register $register = null;

	/**
	 * The register id, once resolved; null when the save names no register.
	 *
	 * @var mixed
	 */
	public mixed $registerId = null;

	/**
	 * The schema the object is saved against, once resolved.
	 *
	 * @var Schema|null
	 */
	public ?Schema $schema = null;

	/**
	 * The schema id, once resolved.
	 *
	 * @var mixed
	 */
	public mixed $schemaId = null;

	/**
	 * The object data to save.
	 *
	 * @var array
	 */
	public array $data = [];

	/**
	 * The `@self` metadata taken off the data.
	 *
	 * @var array
	 */
	public array $selfData = [];

	/**
	 * The identifier to update, or null for a create.
	 *
	 * @var string|null
	 */
	public ?string $uuid = null;

	/**
	 * How the save runs: rights, tenancy, persistence and ownership.
	 *
	 * @var ObjectSaveFlags|null
	 */
	public ?ObjectSaveFlags $flags = null;
}//end class
