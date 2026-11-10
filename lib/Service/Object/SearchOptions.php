<?php

/**
 * OpenRegister SearchOptions
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
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * The optional parts of an object search: the object selection and the views that scope it.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class SearchOptions {

	/**
	 * Constructor.
	 *
	 * @param ObjectSelection         $selection         The ids and used objects the search is restricted to.
	 * @param array<int, string>|null $views             The view ids applied to the query, or null for none.
	 * @param bool                    $viewScopeRequired Whether the views are the caller's only bound, so a
	 *                                                   search that cannot apply them is refused.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		public readonly ObjectSelection $selection = new ObjectSelection(),
		public readonly ?array $views = null,
		public readonly bool $viewScopeRequired = false,
	) {
	}//end __construct()
}//end class
