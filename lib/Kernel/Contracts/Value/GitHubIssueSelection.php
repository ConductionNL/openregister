<?php

/**
 * GitHubIssueSelection: which issues of one repository to list.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * The repository, state, sort order, page size and labels of one issue listing.
 *
 * @spec exclude ADR-011 parameter object of the GitHub client contract, not a capability of its own
 */
final class GitHubIssueSelection {

	/**
	 * Constructor
	 *
	 * @param string             $owner   The repository owner.
	 * @param string             $repo    The repository name.
	 * @param string             $state   Issue state: open, closed or all.
	 * @param string             $sort    GitHub sort key (reactions-+1, created, updated, comments).
	 * @param int                $perPage Number of issues to return.
	 * @param array<string>|null $labels  Labels to filter by; several labels are queried one by one and merged.
	 */
	public function __construct(
		public readonly string $owner,
		public readonly string $repo,
		public readonly string $state = 'open',
		public readonly string $sort = 'reactions-+1',
		public readonly int $perPage = 30,
		public readonly ?array $labels = null,
	) {
	}//end __construct()
}//end class
