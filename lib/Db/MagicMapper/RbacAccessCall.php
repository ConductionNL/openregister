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
	 * Nextcloud's administrator verdict for the caller, set by whoever identified the caller.
	 *
	 * Null when nothing asked Nextcloud; the caller's groups are then read as
	 * before. A verdict wins over the group list, so an `admin` group member
	 * outside `allowed_admin_ranges` is not an administrator here.
	 *
	 * @var boolean|null
	 */
	public ?bool $administrator = null;

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
	 * Set the CRUD action this call checks.
	 *
	 * @param string $action The action (read, create, update, delete).
	 *
	 * @return static This call.
	 */
	public function forAction(string $action): static {
		$this->action = $action;
		return $this;
	}//end forAction()

	/**
	 * Set the bare magic table this call reads, or null.
	 *
	 * @param string|null $tableName The table, so a `_status` column it lacks is left out of the draft predicate.
	 *
	 * @return static This call.
	 */
	public function onTable(?string $tableName): static {
		$this->tableName = $tableName;
		return $this;
	}//end onTable()

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
	 * @return bool Nextcloud's verdict when one was set, else whether the caller is in the admin group.
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
	 */
	public function callerIsAdmin(): bool {
		if ($this->administrator !== null) {
			return $this->administrator;
		}

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
