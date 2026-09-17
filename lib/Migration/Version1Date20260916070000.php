<?php

/**
 * The caller record: who calls which endpoint, and on which contract version.
 *
 * A gemeente deprecating an endpoint has no idea who still calls it, so a
 * deprecation is announced by mailing list and confirmed by breaking. One
 * table ends that: principal, route, method, version, a count and a last-seen.
 *
 * 🔴 NO PAYLOAD COLUMN, DELIBERATELY. Knowing that a leverancier still calls a
 * route is enough to hold the conversation. Recording what they sent is a
 * second copy of case data in a log, which is exactly the failure
 * C-access-and-privacy-54 describes in Plane, and a log is precisely where
 * nobody looks for personal data when a deletion request arrives. There is no
 * column here that could hold a request body, so no later change can quietly
 * start writing one (design D-4).
 *
 * Idempotent: the table is created only when absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the API caller record table.
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */
class Version1Date20260916070000 extends SimpleMigrationStep {

	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_api_calls') === true) {
			return $schema;
		}

		$table = $schema->createTable('openregister_api_calls');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		// The Nextcloud uid, or the empty string for an anonymous caller. Not
		// null, because a unique index over a nullable column does not stop a
		// second anonymous row on every database this app runs on.
		$table->addColumn('principal', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
		// The ROUTE, not the URL. `/api/objects/{register}/{schema}/{id}` and
		// not the thousand paths it expands to, because a per-id row would make
		// this table larger than the objects it describes and answer a question
		// nobody asked.
		$table->addColumn('route', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
		$table->addColumn('method', Types::STRING, ['notnull' => true, 'length' => 10, 'default' => 'GET']);
		$table->addColumn('api_version', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => '1']);
		$table->addColumn('call_count', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
		$table->addColumn('first_seen', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('last_seen', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		// One row per caller, route, method and version. The increment is an
		// UPDATE against this index, so a call costs one indexed write rather
		// than a row.
		$table->addUniqueIndex(
			['principal', 'route', 'method', 'api_version'],
			'idx_or_apicall_unique'
		);
		// The administrator's read: everything that called anything in a period.
		$table->addIndex(['last_seen'], 'idx_or_apicall_seen');
		// The deprecation read: who still calls this version.
		$table->addIndex(['api_version', 'last_seen'], 'idx_or_apicall_version');
		$table->addIndex(['principal'], 'idx_or_apicall_principal');

		$output->info('Created openregister_api_calls table');

		return $schema;

	}//end changeSchema()
}//end class
