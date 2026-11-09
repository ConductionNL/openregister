<?php

/**
 * RenderSelection
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * What a render call asks to see of an object: the relations to extend, the filter it must match, the fields to keep and the properties to drop.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RenderSelection {
	/**
	 * Constructor.
	 *
	 * @param array|string|null $extend Properties to extend the object with
	 * @param array|null        $filter Filters the rendered object must match
	 * @param array|null        $fields Fields to include in the output
	 * @param array|null        $unset  Properties to remove from the output
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly array|string|null $extend,
		public readonly ?array $filter,
		public readonly ?array $fields,
		public readonly ?array $unset,
	) {
	}//end __construct()
}//end class
