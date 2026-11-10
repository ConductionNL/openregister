<?php

/**
 * One call to a repository host's API, as its failure is logged.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
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

namespace OCA\OpenRegister\Service\Configuration;

/**
 * What a GitHub or GitLab API call does and the log keys of its request.
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
 */
final class RepositoryApiCall
{

	/**
	 * Constructor.
	 *
	 * @param string               $action  What the call does, as in "API {action} failed".
	 * @param array<string, mixed> $context The request's log keys.
	 */
	public function __construct(
		public readonly string $action,
		public readonly array $context,
	) {
	}//end __construct()
}//end class
