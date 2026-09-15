<?php

/**
 * Party roles beyond the requester — the party columns on the link table.
 *
 * A party is a record on the object layer, and the row that puts it on an
 * object in a role is the link row people-on-objects already writes. Three
 * columns widen that row from "a user or a contact" to "a user, a contact or
 * a party":
 *
 *  - party_uuid: the uuid of the party object, indexed, so every object a
 *    party holds a role on is one query and an indicator needs no write on
 *    those objects.
 *  - party_kind: the kind the party had when the role was given, so a listing
 *    and a refusal read the kind without loading every party.
 *  - primary_party: which party the object is filed against, so replacing it
 *    is one authorised act on one row rather than a convention per app.
 *
 * Idempotent: each column and index is added only when absent.
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
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\ITable;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Widens the contact link table to a party without an account.
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
 */
class Version1Date20260915040000 extends SimpleMigrationStep {

	/**
	 * The contact link table.
	 */
	private const TABLE = 'openregister_contact_links';

	/**
	 * The index on the party uuid: every object a party holds a role on.
	 */
	private const PARTY_INDEX = 'idx_contact_party_uuid';

	/**
	 * The columns to add, each nullable or defaulted so an existing row stays valid.
	 */
	private const COLUMNS = [
		'party_uuid' => [Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]],
		'party_kind' => [Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]],
		'primary_party' => [Types::BOOLEAN, ['notnull' => false, 'default' => false]],
	];

	/**
	 * Add the three party columns and the party index, each step skipped when done.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return null;
		}

		$table = $schema->getTable(self::TABLE);
		$changed = $this->addColumns(table: $table);
		if ($table->hasIndex(self::PARTY_INDEX) === false) {
			$table->addIndex(['party_uuid'], self::PARTY_INDEX);
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Add the party columns that are still missing.
	 *
	 * @param ITable $table The link table.
	 *
	 * @return bool Whether anything was added.
	 */
	private function addColumns(ITable $table): bool {
		$changed = false;
		foreach (self::COLUMNS as $name => [$type, $options]) {
			if ($table->hasColumn($name) === true) {
				continue;
			}

			$table->addColumn($name, $type, $options);
			$changed = true;
		}

		return $changed;
	}//end addColumns()
}//end class
