<?php

/**
 * One file property of an object being saved, with its resolved file configuration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The object, the property name, whether it holds an array of files and the file configuration.
 */
final readonly class FilePropertyTarget {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity         $objectEntity The object entity being saved.
	 * @param string               $name         The property name.
	 * @param bool                 $isArray      Whether the property is an array of files.
	 * @param array<string, mixed> $fileConfig   The file configuration (the items' for an array).
	 */
	public function __construct(
		public ObjectEntity $objectEntity,
		public string $name,
		public bool $isArray,
		public array $fileConfig,
	) {
	}//end __construct()
}//end class
