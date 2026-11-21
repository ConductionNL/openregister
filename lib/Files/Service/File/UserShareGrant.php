<?php

/**
 * OpenRegister UserShareGrant.
 *
 * One user share to grant on a file or folder: the node, the user it is
 * shared with and the permissions they get.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCP\Files\Node;

/**
 * One user share to grant.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class UserShareGrant {
	/**
	 * Constructor.
	 *
	 * @param Node   $node        The file or folder to share.
	 * @param string $userId      The user it is shared with.
	 * @param int    $permissions The permissions granted (31 = all).
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly Node $node,
		public readonly string $userId,
		public readonly int $permissions = 31,
	) {
	}//end __construct()
}//end class
