<?php

/**
 * One folder bind to check: the folder named, who asks, and the object it would attach to.
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
 * The folder bind a caller asks about, before the acting user is resolved.
 *
 * @spec openspec/specs/self-folder-access-control/spec.md
 */
final class FolderAccessCheck {
	/**
	 * Constructor.
	 *
	 * @param string                   $folderId     The numeric folder id.
	 * @param IUser|null               $currentUser  Explicit acting user; null falls back to the session user.
	 * @param ObjectEntity|string|null $objectEntity The object the bind would attach to (audit context).
	 */
	public function __construct(
		public readonly string $folderId,
		public readonly ?IUser $currentUser=null,
		public readonly ObjectEntity|string|null $objectEntity=null,
	) {
	}//end __construct()
}//end class
