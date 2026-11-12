<?php

/**
 * OpenRegister InverseLookup
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
 * One entity's inverse properties and the objects found to reference it, for the uncached render path.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class InverseLookup {

	/**
	 * The entity being rendered.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $entity = null;

	/**
	 * The inverse property configurations, by property name.
	 *
	 * @var array
	 */
	public array $inversedProperties = [];

	/**
	 * The objects that reference the entity.
	 *
	 * @var ObjectEntity[]
	 */
	public array $referencingObjects = [];
}//end class
