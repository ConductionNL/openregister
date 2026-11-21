<?php

/**
 * A GitLab project at one ref.
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
 * A GitLab project and the branch, tag or commit to read it at.
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
 */
final class GitLabProjectRef
{

	/**
	 * Constructor.
	 *
	 * @param int    $projectId The GitLab project ID.
	 * @param string $ref       The branch, tag or commit.
	 */
	public function __construct(
		public readonly int $projectId,
		public readonly string $ref,
	) {
	}//end __construct()
}//end class
