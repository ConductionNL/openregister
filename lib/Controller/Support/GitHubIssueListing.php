<?php

/**
 * OpenRegister GitHubIssueListing
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * The validated query of one GitHub issue list read: which repository, state, sort and page size.
 *
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-5
 */
final class GitHubIssueListing {
	/**
	 * Hold the fields.
	 *
	 * @param string $repo The caller-supplied `<owner>/<repo>` slug.
	 * @param string $state The issue state filter.
	 * @param string $sort The sort key.
	 * @param int $perPage The page size.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-5
	 */
	public function __construct(
		public readonly string $repo,
		public readonly string $state,
		public readonly string $sort,
		public readonly int $perPage,
	) {
	}//end __construct()
}//end class
