<?php

/**
 * OpenRegister PageRender
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
 * The render options one RenderObject::renderEntities() call applies to every row of a page.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class PageRender {

	/**
	 * The relations to extend on every row.
	 *
	 * @var array
	 */
	public array $extend = [];

	/**
	 * The filters for every row.
	 *
	 * @var array|string|null
	 */
	public array|string|null $filter = null;

	/**
	 * The fields every row keeps.
	 *
	 * @var array|string|null
	 */
	public array|string|null $fields = null;

	/**
	 * The fields every row drops.
	 *
	 * @var array|string|null
	 */
	public array|string|null $unset = null;

	/**
	 * Whether RBAC checks apply.
	 *
	 * @var boolean
	 */
	public bool $rbac = true;

	/**
	 * Whether multitenancy filtering applies.
	 *
	 * @var boolean
	 */
	public bool $multitenancy = true;
}//end class
