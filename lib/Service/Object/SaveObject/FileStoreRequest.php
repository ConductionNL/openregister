<?php

/**
 * OpenRegister FileStoreRequest
 *
 * What storing one file of a file property needs besides the input itself.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The object, the property, its file configuration and the array index of one file being stored.
 *
 * @spec openspec/specs/content-versioning/spec.md
 */
final readonly class FileStoreRequest {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity         $objectEntity The object entity being saved.
	 * @param string               $propertyName The file property.
	 * @param array<string, mixed> $fileConfig   The file property configuration.
	 * @param int|null             $index        The index in an array property, null for a single file.
	 */
	public function __construct(
		public ObjectEntity $objectEntity,
		public string $propertyName,
		public array $fileConfig,
		public ?int $index,
	) {
	}//end __construct()
}//end class
