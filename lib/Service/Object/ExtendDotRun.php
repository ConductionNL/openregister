<?php

/**
 * OpenRegister ExtendDotRun
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

use Adbar\Dot;

/**
 * One RenderObject::handleExtendDot() pass over an object's data, and the extend key it is on.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class ExtendDotRun {

	/**
	 * The object data being extended.
	 *
	 * @var Dot|null
	 */
	public ?Dot $data = null;

	/**
	 * The current render depth.
	 *
	 * @var integer
	 */
	public int $depth = 0;

	/**
	 * Whether every relation is extended.
	 *
	 * @var boolean
	 */
	public bool $allFlag = false;

	/**
	 * The UUIDs already rendered on this path, for cycle detection.
	 *
	 * @var array
	 */
	public array $visitedIds = [];

	/**
	 * The extend entry's key in the extend list; a non-numeric key names where the result goes.
	 *
	 * @var string|integer
	 */
	public string|int $override = 0;

	/**
	 * The dot path being extended.
	 *
	 * @var string
	 */
	public string $key = '';

	/**
	 * The nested extends below the key, relative to it.
	 *
	 * @var array
	 */
	public array $keyExtends = [];

	/**
	 * The relation descriptor that declares the key's exposed field set, if any.
	 *
	 * @var mixed
	 */
	public mixed $descriptor = null;
}//end class
