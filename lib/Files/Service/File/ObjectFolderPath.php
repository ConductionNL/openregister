<?php

/**
 * A folder of one object, by its path relative to the object folder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The object and a folder path inside its object folder.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class ObjectFolderPath {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object, already known to be changeable.
	 * @param string       $path   The folder, relative to the object folder.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly string $path,
	) {
	}//end __construct()
}//end class
