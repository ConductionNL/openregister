<?php

/**
 * OpenRegister BulkRelationRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObjects
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObjects;

use OCA\OpenRegister\Db\Schema;

/**
 * One pass of the bulk relation scan over a level of object data, and where it is.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class BulkRelationRun {

	/**
	 * The property definitions of the schema, when there is one.
	 *
	 * @var array|null
	 */
	public ?array $schemaProperties = null;

	/**
	 * The relations found so far, by dot path.
	 *
	 * @var array
	 */
	public array $relations = [];

	/**
	 * The property being scanned.
	 *
	 * @var string
	 */
	public string $key = '';

	/**
	 * The dot path of the property being scanned.
	 *
	 * @var string
	 */
	public string $path = '';

	/**
	 * The dot path of the array item being scanned.
	 *
	 * @var string
	 */
	public string $itemPath = '';

	/**
	 * Whether the schema declares the property as an array of objects.
	 *
	 * @var boolean
	 */
	public bool $itemsAreObjects = false;

	/**
	 * @param string      $prefix The dot path of the level, empty at the top.
	 * @param Schema|null $schema The schema the data is scanned against, if any.
	 */
	public function __construct(
		public readonly string $prefix = '',
		public readonly ?Schema $schema = null,
	) {
	}//end __construct()

	/**
	 * Put the scan on a key of its level.
	 *
	 * @param string $key The key.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function moveTo(string $key): void {
		$this->key = $key;
		$this->path = $key;
		if ($this->prefix !== '') {
			$this->path = $this->prefix . '.' . $key;
		}
	}//end moveTo()
}//end class
