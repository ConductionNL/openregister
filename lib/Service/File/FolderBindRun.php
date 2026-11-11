<?php

/**
 * One check of a folder bind: the folder named, who asks, and the object it would attach to.
 *
 * Built by {@see FolderManagementHandler::assertFolderIsAccessible()} once the
 * acting user is resolved, and read by its checks and the denial audit. It
 * never leaves that class.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/self-folder-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;

/**
 * The folder bind under check.
 *
 * @spec openspec/specs/self-folder-access-control/spec.md
 */
final class FolderBindRun {
	/**
	 * Constructor.
	 *
	 * @param string                   $folderId     The numeric folder id the caller supplied.
	 * @param IUser|null               $actingUser   The resolved acting user; null means nobody (denied).
	 * @param ObjectEntity|string|null $objectEntity The object the bind would attach to (audit context).
	 */
	public function __construct(
		public readonly string $folderId,
		public readonly ?IUser $actingUser,
		public readonly ObjectEntity|string|null $objectEntity,
	) {
	}//end __construct()
}//end class
