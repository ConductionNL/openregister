<?php

/**
 * One GitHub issue submission together with the credential it is sent with.
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

use OCA\OpenRegister\Kernel\Contracts\Value\GitHubIssueSubmission;

/**
 * Where the issue goes, who submits it, and whether the server PAT carries it.
 *
 * The token and the server-PAT flag travel together from the token
 * resolution to the request, the attribution and the server-PAT audit line.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final readonly class GitHubIssueDelivery {

	/**
	 * Constructor.
	 *
	 * @param GitHubIssueSubmission $submission   Where the issue goes and who submits it.
	 * @param string                $token        The bearer token the request is sent with.
	 * @param bool                  $useServerPat Whether that token is the server PAT.
	 */
	public function __construct(
		public GitHubIssueSubmission $submission,
		public string $token,
		public bool $useServerPat,
	) {
	}//end __construct()
}//end class
