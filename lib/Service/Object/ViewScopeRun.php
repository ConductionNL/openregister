<?php

/**
 * ViewScopeRun: the views one search is scoped by.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The view ids of one search, and whether they are its only bound.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class ViewScopeRun {
	/**
	 * Constructor.
	 *
	 * @param array<int|string> $viewIds  The views to apply.
	 * @param bool              $required Whether a view that cannot be applied refuses the search.
	 */
	public function __construct(
		public readonly array $viewIds,
		public readonly bool $required,
	) {
	}//end __construct()
}//end class
