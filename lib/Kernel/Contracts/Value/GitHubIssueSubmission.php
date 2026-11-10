<?php

/**
 * One GitHub issue submission: where it goes, who submits it, and its capability.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
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

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * The fields GitHubHandler sends an issue to and audits a server-PAT submission with.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class GitHubIssueSubmission {
	/**
	 * Constructor.
	 *
	 * @param string      $owner   GitHub repo owner.
	 * @param string      $repo    GitHub repo name.
	 * @param string      $userId  Nextcloud UID of the submitting user.
	 * @param string|null $specRef Validated capability slug, or null.
	 */
	public function __construct(
		public readonly string $owner,
		public readonly string $repo,
		public readonly string $userId,
		public readonly ?string $specRef,
	) {
	}//end __construct()

	/**
	 * The `<owner>/<repo>` slug, as the audit entry names the repository.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function repoSlug(): string {
		return $this->owner.'/'.$this->repo;
	}//end repoSlug()

	/**
	 * The Issues API URL of the repository, owner and name URL-encoded.
	 *
	 * @param string $apiBase The GitHub API base URL.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function issuesUrl(string $apiBase): string {
		return $apiBase.'/repos/'.rawurlencode($this->owner).'/'.rawurlencode($this->repo).'/issues';
	}//end issuesUrl()
}//end class
