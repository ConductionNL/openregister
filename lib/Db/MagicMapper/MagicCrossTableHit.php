<?php

/**
 * MagicCrossTableHit
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * A row an identifier lookup located in one magic table, with the lookup's
 * flags and, once loaded, the table's register and schema.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class MagicCrossTableHit {

	/**
	 * The magic table name, unprefixed.
	 *
	 * @var string
	 */
	public string $tableName = '';

	/**
	 * The `_id` of the located row.
	 *
	 * @var integer
	 */
	public int $id = 0;

	/**
	 * The fetched row.
	 *
	 * @var array<string, mixed>
	 */
	public array $row = [];

	/**
	 * The table's register id.
	 *
	 * @var integer|string
	 */
	public int|string $registerId = 0;

	/**
	 * The table's schema id.
	 *
	 * @var integer|string
	 */
	public int|string $schemaId = 0;

	/**
	 * The table's register, when it could be loaded.
	 *
	 * @var Register|null
	 */
	public ?Register $register = null;

	/**
	 * The table's schema, when it could be loaded.
	 *
	 * @var Schema|null
	 */
	public ?Schema $schema = null;

	/**
	 * Whether soft-deleted rows match.
	 *
	 * @var boolean
	 */
	public bool $includeDeleted = false;

	/**
	 * Whether RBAC applies.
	 *
	 * @var boolean
	 */
	public bool $rbac = true;

	/**
	 * Whether multitenancy applies.
	 *
	 * @var boolean
	 */
	public bool $multitenancy = true;

	/**
	 * Constructor.
	 *
	 * @param int|string $identifier The identifier that was looked up.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function __construct(
		public readonly int|string $identifier,
	) {
	}//end __construct()
}//end class
