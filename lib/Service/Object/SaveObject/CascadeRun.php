<?php

/**
 * OpenRegister CascadeRun
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

/**
 * One pass of SaveObject over a parent's data, property by property (cascade or write-back), and the property it is on.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class CascadeRun {

	/**
	 * The parent object.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $parent = null;

	/**
	 * The parent's data, rewritten as properties cascade.
	 *
	 * @var array
	 */
	public array $data = [];

	/**
	 * The parent's stored data when its schema opted into orphan cleanup, else empty.
	 *
	 * @var array
	 */
	public array $stored = [];

	/**
	 * The name of the property being cascaded.
	 *
	 * @var string|integer
	 */
	public string|int $property = '';

	/**
	 * The schema definition of the property being cascaded.
	 *
	 * @var array
	 */
	public array $definition = [];

	/**
	 * Whether the property's objects are related objects (handling relates).
	 *
	 * @var boolean
	 */
	public bool $isRelated = false;

	/**
	 * The references the stored parent held in the property that may become orphans.
	 *
	 * @var string[]
	 */
	public array $oldUuids = [];

	/**
	 * A run over one parent object and the data to prepare for it.
	 *
	 * @param ObjectEntity $parent The parent object.
	 * @param array        $data   The parent's data.
	 *
	 * @return self The run.
	 */
	public static function forObject(ObjectEntity $parent, array $data): self {
		$run         = new self();
		$run->parent = $parent;
		$run->data   = $data;

		return $run;
	}//end forObject()
}//end class
