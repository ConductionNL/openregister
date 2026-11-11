<?php

/**
 * OwnershipTransferRun
 *
 * One file or folder ownership transfer: the node, what it is called in the
 * log lines, the optional sharing handler that shares it back, and the two
 * accounts once they are known.
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
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCP\Files\Node;

/**
 * The node, kind, sharing handler and accounts of one ownership transfer.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class OwnershipTransferRun {

	/**
	 * The handler that shares the node back to the caller; none means no share.
	 *
	 * @var FileSharingHandlerInterface|null
	 */
	public ?FileSharingHandlerInterface $sharing = null;

	/**
	 * The signed-in caller's uid, once known.
	 *
	 * @var string
	 */
	public string $currentUserId = '';

	/**
	 * The OpenRegister system account's uid, once known.
	 *
	 * @var string
	 */
	public string $openRegisterUserId = '';

	/**
	 * Constructor.
	 *
	 * @param Node   $node The file or folder.
	 * @param string $noun `file` or `folder`, as the log lines name it.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly Node $node,
		public readonly string $noun,
	) {
	}//end __construct()

	/**
	 * Whether the node is a file (shared back as a file) rather than a folder.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function isFile(): bool {
		return $this->noun === 'file';
	}//end isFile()
}//end class
