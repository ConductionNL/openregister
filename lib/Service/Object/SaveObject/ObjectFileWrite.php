<?php

/**
 * OpenRegister ObjectFileWrite
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
 * The file properties of one saved object being stored, with the data they rewrite.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class ObjectFileWrite {

	/**
	 * The saved object whose file properties are stored.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $entity = null;

	/**
	 * The object data; file handling replaces file content with file ids.
	 *
	 * @var array
	 */
	public array $data = [];

	/**
	 * The register of the object.
	 *
	 * @var Register|null
	 */
	public ?Register $register = null;

	/**
	 * The schema of the object.
	 *
	 * @var Schema|null
	 */
	public ?Schema $schema = null;
}//end class
