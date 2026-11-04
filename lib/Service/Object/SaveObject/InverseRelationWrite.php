<?php

/**
 * OpenRegister InverseRelationWrite
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
 * One saved object's pass over its relations to write itself into each related object, and the relation it is on.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class InverseRelationWrite {

	/**
	 * The UUID of the saved object.
	 *
	 * @var string|null
	 */
	public ?string $savedUuid = null;

	/**
	 * The register the saved object belongs to.
	 *
	 * @var Register|null
	 */
	public ?Register $register = null;

	/**
	 * The schema the saved object belongs to.
	 *
	 * @var Schema|null
	 */
	public ?Schema $schema = null;

	/**
	 * The saved object's schema properties.
	 *
	 * @var array
	 */
	public array $schemaProperties = [];

	/**
	 * The property path of the relation (e.g. "organisatie.0").
	 *
	 * @var string
	 */
	public string $propertyPath = '';

	/**
	 * The UUID the relation points at.
	 *
	 * @var string
	 */
	public string $relatedUuid = '';
}//end class
