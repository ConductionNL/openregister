<?php

/**
 * RenderContext
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
 * The options one RenderObject::renderEntity() call renders an object with.
 *
 * `fields` already carries `@self` and `id` when the caller selected fields,
 * and `visitedIds` already carries the object being rendered.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RenderContext {
	/**
	 * Constructor.
	 *
	 * @param array|string|null $extend     Properties to extend the object with
	 * @param int               $depth      The depth level for nested rendering
	 * @param array|null        $filter     Filters the rendered object must match
	 * @param array|null        $fields     Fields to include in the output
	 * @param array|null        $unset      Properties to remove from the output
	 * @param array|null        $registers  Preloaded registers
	 * @param array|null        $schemas    Preloaded schemas
	 * @param array|null        $objects    Preloaded objects
	 * @param array|null        $visitedIds Uuids already rendered on this path
	 * @param bool              $rbac       Whether access control applies
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) A value object carrying every render option
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    The rbac flag is part of the render options
	 */
	public function __construct(
		public readonly array|string|null $extend,
		public readonly int $depth,
		public readonly ?array $filter,
		public readonly ?array $fields,
		public readonly ?array $unset,
		public readonly ?array $registers,
		public readonly ?array $schemas,
		public readonly ?array $objects,
		public readonly ?array $visitedIds,
		public readonly bool $rbac,
	) {
	}//end __construct()

	/**
	 * The same context with a different extend list.
	 *
	 * @param array|string|null $extend The extend list
	 *
	 * @return self
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function withExtend(array|string|null $extend): self {
		return new self(
			extend: $extend,
			depth: $this->depth,
			filter: $this->filter,
			fields: $this->fields,
			unset: $this->unset,
			registers: $this->registers,
			schemas: $this->schemas,
			objects: $this->objects,
			visitedIds: $this->visitedIds,
			rbac: $this->rbac
		);
	}//end withExtend()
}//end class
