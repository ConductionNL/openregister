<?php

/**
 * OpenRegister file action target
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Files\Controller\Support
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

namespace OCA\OpenRegister\Files\Controller\Support;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The file a file action works on, with the object it belongs to.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileActionTarget {
	/**
	 * Name the file of an action.
	 *
	 * @param ObjectEntity $object The object.
	 * @param int          $fileId The file.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly int $fileId,
	) {
	}//end __construct()
}//end class
