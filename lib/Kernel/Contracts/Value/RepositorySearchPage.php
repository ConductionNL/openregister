<?php

/**
 * One page of a configuration search on a repository host.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * The page number and page size a GitHub or GitLab search asks for.
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
 */
final class RepositorySearchPage
{

	/**
	 * Constructor.
	 *
	 * @param int $page    The page number, counted from 1.
	 * @param int $perPage The page size as the caller asked for it.
	 */
	public function __construct(
		public readonly int $page,
		public readonly int $perPage,
	) {
	}//end __construct()
}//end class
