<?php

/**
 * OpenRegister InverseTarget
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

/**
 * Where one inverse property looks: the fields on other objects that point back, their schema, and its cardinality.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class InverseTarget {

	/**
	 * The field names on referencing objects that may hold the entity's UUID.
	 *
	 * @var array
	 */
	public array $fields = [];

	/**
	 * The schema the referencing objects must belong to.
	 *
	 * @var string|integer|null
	 */
	public string|int|null $schemaId = null;

	/**
	 * Whether the inverse property holds a list.
	 *
	 * @var boolean
	 */
	public bool $isArray = false;

	/**
	 * The UUID the fields must point at.
	 *
	 * @var string|null
	 */
	public ?string $entityUuid = null;
}//end class
