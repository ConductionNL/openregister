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
	 * @param RenderSelection $selection What the caller asks to see: extend, filter, fields and unset
	 * @param RenderPreloads  $preloads  The registers, schemas and objects already loaded
	 * @param RenderPath      $path      The depth and the uuids already rendered on this path
	 * @param bool            $rbac      Whether access control applies
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The rbac flag is part of the render options
	 */
	public function __construct(
		public readonly RenderSelection $selection,
		public readonly RenderPreloads $preloads,
		public readonly RenderPath $path,
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
		$selection = new RenderSelection(
			extend: $extend,
			filter: $this->selection->filter,
			fields: $this->selection->fields,
			unset: $this->selection->unset
		);

		return new self(selection: $selection, preloads: $this->preloads, path: $this->path, rbac: $this->rbac);
	}//end withExtend()
}//end class
