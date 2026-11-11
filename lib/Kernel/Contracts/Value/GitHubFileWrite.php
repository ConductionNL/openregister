<?php

/**
 * GitHubFileWrite: the new content of one repository file and its commit.
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
 * What a publish writes to a GitHub file: the content, the commit message and, for an update, the current blob SHA.
 *
 * @spec exclude ADR-011 parameter object of the GitHub client contract, not a capability of its own
 */
final class GitHubFileWrite {

	/**
	 * Constructor
	 *
	 * @param string      $content       The file content (JSON string).
	 * @param string      $commitMessage The commit message.
	 * @param string|null $fileSha       The current blob SHA when updating an existing file; null creates it.
	 */
	public function __construct(
		public readonly string $content,
		public readonly string $commitMessage,
		public readonly ?string $fileSha = null,
	) {
	}//end __construct()
}//end class
