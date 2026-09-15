<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * People on objects: the contact link table takes a Nextcloud user beside a
 * CardDAV contact, a validity window and a note, and one person may hold
 * several roles on one object.
 *
 * - `user_id` names the user of a user link; `addressbook_id` and
 *   `contact_uri` become nullable because a user has neither. The
 *   `contact_uid` of a user link is `user:<uid>`, so every index and route
 *   keyed on that column keeps working.
 * - `valid_from` / `valid_until` are dates; `note` is free text.
 * - The unique key moves from (object, person) to (object, person, role).
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
 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Widens the contact link table to users, validity and several roles per person.
 *
 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
 */
class Version1Date20260913180000 extends SimpleMigrationStep {

	/**
	 * The contact link table.
	 */
	private const TABLE = 'openregister_contact_links';

	/**
	 * The unique index this migration retires: one row per (object, person).
	 */
	private const OLD_UNIQUE = 'idx_contact_object_uid_uniq';

	/**
	 * The unique index this migration adds: one row per (object, person, role).
	 */
	private const NEW_UNIQUE = 'idx_contact_obj_uid_role_uniq';

	/**
	 * The index on the user id.
	 */
	private const USER_INDEX = 'idx_contact_user_id';

	/**
	 * The columns to add, each nullable with no default.
	 */
	private const COLUMNS = [
		'user_id' => [Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]],
		'valid_from' => [Types::DATE_MUTABLE, ['notnull' => false, 'default' => null]],
		'valid_until' => [Types::DATE_MUTABLE, ['notnull' => false, 'default' => null]],
		'note' => [Types::TEXT, ['notnull' => false, 'default' => null]],
	];

	/**
	 * The columns a user link leaves empty.
	 */
	private const RELAXED = ['addressbook_id', 'contact_uri'];

	/**
	 * Add the columns and move the unique key, each step skipped when done.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return null;
		}

		$table = $schema->getTable(self::TABLE);
		$changed = $this->addColumns(table: $table);
		$changed = $this->relaxColumns(table: $table) || $changed;
		$changed = $this->moveIndexes(table: $table) || $changed;
		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Add the four people-on-objects columns that are still missing.
	 *
	 * @param Table $table The link table.
	 *
	 * @return bool Whether anything was added.
	 */
	private function addColumns(Table $table): bool {
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

	/**
	 * Make the contact-only columns nullable where they are not yet.
	 *
	 * @param Table $table The link table.
	 *
	 * @return bool Whether anything was relaxed.
	 */
	private function relaxColumns(Table $table): bool {
		$changed = false;
		foreach (self::RELAXED as $name) {
			if ($table->hasColumn($name) === false) {
				continue;
			}

			$column = $table->getColumn($name);
			if ($column->getNotnull() === false) {
				continue;
			}

			$column->setNotnull(false);
			$changed = true;
		}

		return $changed;
	}//end relaxColumns()

	/**
	 * Retire the (object, person) unique key for (object, person, role), and index the user id.
	 *
	 * @param Table $table The link table.
	 *
	 * @return bool Whether an index changed.
	 */
	private function moveIndexes(Table $table): bool {
		$changed = false;
		if ($table->hasIndex(self::OLD_UNIQUE) === true) {
			$table->dropIndex(self::OLD_UNIQUE);
			$changed = true;
		}

		if ($table->hasIndex(self::NEW_UNIQUE) === false) {
			$table->addUniqueIndex(['object_uuid', 'contact_uid', 'role'], self::NEW_UNIQUE);
			$changed = true;
		}

		if ($table->hasIndex(self::USER_INDEX) === false) {
			$table->addIndex(['user_id'], self::USER_INDEX);
			$changed = true;
		}

		return $changed;
	}//end moveIndexes()
}//end class
