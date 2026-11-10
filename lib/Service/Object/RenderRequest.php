<?php

/**
 * RenderRequest
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
 * What one RenderObject::render() call is asked to render an object with.
 *
 * Unlike RenderContext it holds the caller's own values: `fields` without the
 * identity fields and `visitedIds` without the object being rendered.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RenderRequest {
	/**
	 * Constructor.
	 *
	 * @param RenderSelection $selection What the caller asks to see: extend, filter, fields and unset
	 * @param RenderPreloads  $preloads  The registers, schemas and objects already loaded
	 * @param RenderPath      $path      The depth and the uuids already rendered on this path
	 */
	public function __construct(
		public readonly RenderSelection $selection,
		public readonly RenderPreloads $preloads,
		public readonly RenderPath $path,
	) {
	}//end __construct()

	/**
	 * A nested render at the given depth: no selection beyond the extend list, nothing preloaded.
	 *
	 * @param array|string|null $extend The extend list
	 * @param RenderPath        $path   The depth and the uuids already rendered on this path
	 *
	 * @return self
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public static function nested(array|string|null $extend, RenderPath $path): self {
		return new self(
			selection: new RenderSelection(extend: $extend, filter: [], fields: [], unset: []),
			preloads: new RenderPreloads(registers: [], schemas: [], objects: []),
			path: $path
		);
	}//end nested()

	/**
	 * A top-level render of one page row: the page's selection, nothing preloaded, depth 0.
	 *
	 * @param RenderSelection $selection What every row shows
	 *
	 * @return self
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public static function page(RenderSelection $selection): self {
		return new self(
			selection: $selection,
			preloads: new RenderPreloads(registers: [], schemas: [], objects: []),
			path: new RenderPath(depth: 0, visitedIds: [])
		);
	}//end page()
}//end class
