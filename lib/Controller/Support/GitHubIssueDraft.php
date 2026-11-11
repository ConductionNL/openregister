<?php

/**
 * OpenRegister GitHubIssueDraft
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
 * The caller-supplied fields of one GitHub issue submission.
 *
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-6
 */
final class GitHubIssueDraft {
	/**
	 * Hold the fields.
	 *
	 * @param string $repo The caller-supplied `<owner>/<repo>` slug.
	 * @param string $title The issue title.
	 * @param string $body The issue body.
	 * @param ?string $specRef The capability slug, or null.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-6
	 */
	public function __construct(
		public readonly string $repo,
		public readonly string $title,
		public readonly string $body,
		public readonly ?string $specRef,
	) {
	}//end __construct()
}//end class
