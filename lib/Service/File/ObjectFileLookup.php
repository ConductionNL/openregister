<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One lookup of a file in an object's folder, by id or by path.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
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

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\Folder;

/**
 * The object and the file id or path asked for, the operation the lookup
 * serves (it prefixes every log line), and what the path resolved to.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class ObjectFileLookup
{

	/**
	 * The log prefix, naming the handler and the operation.
	 *
	 * @var string
	 */
	public string $prefix = '';

	/**
	 * The cleaned path, for a lookup by path.
	 *
	 * @var string
	 */
	public string $filePath = '';

	/**
	 * The file name taken from the path, for a lookup by path.
	 *
	 * @var string
	 */
	public string $fileName = '';

	/**
	 * The object's folder, once found.
	 *
	 * @var Folder|null
	 */
	public ?Folder $folder = null;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object whose folder holds the file.
	 * @param int|string   $file   The file id, or its path.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly int|string $file,
	) {
	}//end __construct()
}//end class
