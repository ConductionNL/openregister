<?php

/**
 * OpenRegister RelationScanLevel
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
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

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;

/**
 * A relation scan over one level of object data: its schema, the relations found, and the key it is on.
 *
 * Shared by the single-object scan (RelationScanRun) and the bulk scan (BulkRelationRun).
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
abstract class RelationScanLevel {

	/**
	 * The schema's explicit-reveal property names, read on first use.
	 *
	 * @var list<string>|null
	 */
	private ?array $explicitProperties = null;

	/**
	 * The schema's property definitions, or null without a readable schema.
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
	 * The data key being scanned.
	 *
	 * @var string
	 */
	public string $key = '';

	/**
	 * The dot path of the key being scanned.
	 *
	 * @var string
	 */
	public string $path = '';

	/**
	 * @param string      $prefix The dot path of the level being scanned, empty at the top.
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

	/**
	 * Whether a top-level key is an explicit-reveal property, whose values stay out of the relations index.
	 *
	 * The relations index answers `_relations_contains` and used-by listings
	 * without the reveal check, so a value only shown on explicit reveal is
	 * never put in it.
	 *
	 * @param string $key The key on this level.
	 *
	 * @return bool True for an explicit-reveal property of the schema on the top level.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function withholds(string $key): bool {
		if ($this->prefix !== '' || $this->schema === null) {
			return false;
		}

		$this->explicitProperties ??= ExplicitReveal::explicitProperties(schema: $this->schema);

		return in_array($key, $this->explicitProperties, true);
	}//end withholds()
}//end class
