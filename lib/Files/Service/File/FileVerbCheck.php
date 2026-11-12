<?php

/**
 * FileVerbCheck: one question whether a file verb lets the caller through on an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The object, the schema that carries its rule, and the file verb asked about.
 *
 * Built and read by ObjectFileAccess only.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileVerbCheck {

	/**
	 * Constructor.
	 *
	 * @param Schema       $schema The object's schema.
	 * @param ObjectEntity $object The object.
	 * @param string       $verb   The file verb.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly ObjectEntity $object,
		public readonly string $verb,
	) {
	}//end __construct()
}//end class
