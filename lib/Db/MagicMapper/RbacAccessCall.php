<?php

/**
 * RbacAccessCall
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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\RuleColumns;

/**
 * One RBAC access decision over a magic table: the schema and action it
 * decides, the table and the column alias it is written against, the
 * caller, and the schema's resolved authorization block.
 *
 * Shared by the QueryBuilder filter ({@see RbacFilterCall}) and the raw-SQL
 * predicate emitter, so both build the same deny, reachability and owner terms.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
class RbacAccessCall {

	/**
	 * The CRUD action being checked.
	 *
	 * @var string
	 */
	public string $action = 'read';

	/**
	 * The bare magic table being read, or null.
	 *
	 * @var string|null
	 */
	public ?string $tableName = null;

	/**
	 * The calling user's id, or null for an anonymous caller.
	 *
	 * @var string|null
	 */
	public ?string $userId = null;

	/**
	 * The calling user's group ids.
	 *
	 * @var array<int, string>
	 */
	public array $userGroups = [];

	/**
	 * The schema's resolved authorization block; null when it has none.
	 *
	 * @var array|null
	 */
	public ?array $authorization = null;

	/**
	 * Constructor.
	 *
	 * @param Schema $schema       The schema whose authorization decides.
	 * @param string $columnPrefix The table alias with its dot the columns are qualified with, or ''.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly string $columnPrefix = '',
	) {
	}//end __construct()

	/**
	 * The rule-key columns of the schema, qualified with this call's prefix.
	 *
	 * @return RuleColumns The columns.
	 */
	public function columns(): RuleColumns {
		return new RuleColumns(schema: $this->schema, prefix: $this->columnPrefix);
	}//end columns()

	/**
	 * Whether the caller is an administrator.
	 *
	 * @return bool True when the caller is in the admin group.
	 */
	public function callerIsAdmin(): bool {
		return in_array('admin', $this->userGroups, true);
	}//end callerIsAdmin()

	/**
	 * The column holding the row's authorization block.
	 *
	 * @return string The qualified column.
	 */
	public function authorizationColumn(): string {
		return $this->columnPrefix . '_authorization';
	}//end authorizationColumn()
}//end class
