<?php

/**
 * Migration creating `oc_openregister_delegation_grants`.
 *
 * The store that answers "may this principal act as that user". See
 * {@see \OCA\OpenRegister\Db\DelegationGrant} for why it is a table rather than
 * an OpenRegister object: a grant governed by the RBAC it decides cannot be read
 * without first deciding what it is being read to decide.
 *
 * ADDITIVE ONLY. No existing row is touched and nothing starts refusing because
 * this ran — the enforcement that consults this table lands separately, behind a
 * measured blast radius. A migration that both creates a store and switches on a
 * refusal gives an operator no way to inspect the first before the second bites.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the delegation-grant store.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
class Version1Date20260824220000 extends SimpleMigrationStep {

	/**
	 * The table created here.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_delegation_grants';

	/**
	 * Create the table.
	 *
	 * @param IOutput $output The migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array $options Migration options.
	 *
	 * @return ISchemaWrapper|null The altered schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $output and $options are part of the base signature.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/* @var ISchemaWrapper $schema The schema wrapper. */
		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE) === true) {
			return null;
		}

		$table = $schema->createTable(self::TABLE);

		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);

		// NOT NULL on both sides of the delegation. A grant that names only one
		// party permits nothing and can only ever confuse a reader — unlike a
		// flow run, where a null identity was a state the system could reach,
		// there is no path that legitimately creates half a grant.
		$table->addColumn('principal', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('acting_as', Types::STRING, ['notnull' => true, 'length' => 64]);

		$table->addColumn('scope', Types::JSON, ['notnull' => false]);
		$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('granted_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('reason', Types::TEXT, ['notnull' => false]);
		$table->addColumn('organisation', Types::STRING, ['notnull' => false, 'length' => 64]);

		$table->addColumn('requested_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('answered_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('expires_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('revoked_at', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'or_delgrant_uuid');

		// The lookup on the hot path: "does this principal hold anything over
		// this identity". Every access decision that involves a named identity
		// runs it, so it is indexed rather than left to a scan that grows with
		// the instance.
		$table->addIndex(['principal', 'acting_as'], 'or_delgrant_pair');

		// The consent inbox, and the audit question "who can act as me".
		$table->addIndex(['acting_as', 'status'], 'or_delgrant_inbox');

		return $schema;
	}//end changeSchema()
}//end class
