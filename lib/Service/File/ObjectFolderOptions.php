<?php

/**
 * The optional inputs of creating an object's folder.
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
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCP\IUser;

/**
 * Who the folder is made for, and the register when the object does not name it.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class ObjectFolderOptions {
	/**
	 * Constructor.
	 *
	 * @param IUser|null      $currentUser The current user; null falls back to the session user.
	 * @param int|string|null $registerId  The register of the object, when the object does not name it.
	 */
	public function __construct(
		public readonly ?IUser $currentUser=null,
		public readonly int|string|null $registerId=null,
	) {
	}//end __construct()
}//end class
