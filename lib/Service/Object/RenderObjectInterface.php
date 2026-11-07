<?php

/**
 * RenderObjectInterface: what callers outside the Service component ask of RenderObject.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The list-rendering calls the objects and trash controllers make. What each
 * call strips, resolves or checks stays in RenderObject; this only names them.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
interface RenderObjectInterface {

	/**
	 * Attach the lightweight `\@self.files` ID list to a batch of un-rendered rows.
	 *
	 * @param array $rows Result rows by reference. Each row is mutated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function attachLightweightFilesToRows(array &$rows): void;

	/**
	 * Resolve translatable properties on a batch of un-rendered list rows.
	 *
	 * @param array $rows Result rows by reference. Each row is mutated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function resolveTranslationsForRows(array &$rows): void;

	/**
	 * Resolve the declared lenses on a batch of un-rendered list rows.
	 *
	 * @param array $rows  Result rows by reference; ObjectEntity rows are mutated in place.
	 * @param bool  $_rbac Whether the reader's access to the referenced object is checked.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lenses/spec.md
	 */
	public function applyLensesToRows(array &$rows, bool $_rbac=true): void;

	/**
	 * Strip writeOnly properties from a batch of list rows, on every read.
	 *
	 * @param array $rows  Result rows by reference, mutated in place.
	 * @param bool  $_rbac Whether this is a user-facing read (true) or system context (false).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-writeonly-properties-must-never-be-returned-on-any-read
	 */
	public function redactWriteOnlyFromRows(array &$rows, bool $_rbac = true): void;

	/**
	 * Render multiple entities with extensions, filters, and field selections.
	 *
	 * @param array             $entities      Array of ObjectEntity instances to render.
	 * @param array|string|null $_extend       Properties to extend/embed in the response.
	 * @param array|string|null $_filter       Filters to apply to the rendered entities.
	 * @param array|string|null $_fields       Specific fields to include in the response.
	 * @param array|string|null $_unset        Fields to exclude from the response.
	 * @param bool              $_rbac         Whether to apply RBAC checks (default: true).
	 * @param bool              $_multitenancy Whether to apply multitenancy filtering (default: true).
	 *
	 * @return ObjectEntity[]
	 *
	 * @psalm-return list<ObjectEntity>
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function renderEntities(
		array $entities,
		array|string|null $_extend = [],
		array|string|null $_filter = null,
		array|string|null $_fields = null,
		array|string|null $_unset = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array;
}//end interface
