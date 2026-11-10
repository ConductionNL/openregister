<?php

/**
 * The title and body of one GitHub issue submission.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
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

namespace OCA\OpenRegister\Service\Configuration;

/**
 * The text GitHubHandler::fileIssue() sends, validated by the caller.
 *
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-2
 */
final class GitHubIssueText {
	/**
	 * Constructor.
	 *
	 * @param string $title Issue title (3-200 chars).
	 * @param string $body  Issue body (at least 10 chars).
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $body,
	) {
	}//end __construct()
}//end class
