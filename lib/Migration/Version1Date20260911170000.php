<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The registry-subscriptions state table (finding B22): one row per object
 * that has requested a registry subscription (`x-openregister-registry`),
 * keyed by object uuid, per design.md D-2 of `registry-subscriptions`.
 *
 * Deliberately NOT a copy of the subscribed person or company. The object
 * OpenRegister already holds — in whichever app's register declared the
 * schema — stays the only copy; this table is bookkeeping about its
 * subscription state, not a second store of its data.
 *
 * `registry` + `identity_value` is NOT unique: two different objects (in
 * different registers, e.g. two apps each keeping their own `person`
 * schema) may legitimately subscribe to the same real-world BSN or KvK
 * number, and the inbound update endpoint updates every matching active
 * row, not just one.
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
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the registry subscription state table.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
 */
class Version1Date20260911170000 extends SimpleMigrationStep {

	/**
	 * The registry subscription state table.
	 */
	private const TABLE = 'openregister_registry_subs';

	/**
	 * Create the table when absent.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE) === true) {
			return null;
		}

		$table = $schema->createTable(self::TABLE);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('register', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('schema', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('registry', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('identity_value', Types::STRING, ['notnull' => true, 'length' => 255]);
		// One of: none, requested, active, ended.
		$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'none']);
		$table->addColumn('last_update_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
		$table->addColumn('last_update_source', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('refusal_reason', Types::TEXT, ['notnull' => false]);
		$table->addColumn('created_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
		$table->addColumn('updated_at', Types::DATETIME_MUTABLE, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		// One subscription row per object: requesting again updates the row.
		$table->addUniqueIndex(['object_uuid'], 'or_registry_sub_object');
		// The inbound endpoint's lookup path: every object subscribed to a
		// given registry+identity, filtered to active rows.
		$table->addIndex(['registry', 'identity_value', 'state'], 'or_registry_sub_lookup');
		// The `_registry[state]` / `_registry[updatedBefore]` query lenses.
		$table->addIndex(['state', 'last_update_at'], 'or_registry_sub_state_updated');

		return $schema;
	}//end changeSchema()
}//end class
