<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The access-link table: one row per link handed to somebody without an account.
 *
 * The anchor is the secret, so it is unique and indexed: a lookup is one row
 * read and the public endpoint never scans. `expires_at` is NOT NULL because
 * the expiry is required at mint, and a nullable column would let a link
 * without an end date exist as soon as one caller forgot to pass one.
 *
 * `password_hash` holds a hash and never a password. The subject columns are a
 * (type, id) pair rather than three nullable foreign keys, because a link over
 * a view and a link over an object are the same kind of row with a different
 * subject, and the read path switches on the type once.
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
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the access-link table.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
class Version1Date20260915204500 extends SimpleMigrationStep {

	/**
	 * The access-link table.
	 */
	private const LINKS_TABLE = 'openregister_access_links';

	/**
	 * Create the table when it is absent.
	 *
	 * Add-only: nothing existing is altered, renamed or dropped.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable(self::LINKS_TABLE) === true) {
			$output->info('access links: table already present, nothing to do');
			return null;
		}

		$table = $schema->createTable(self::LINKS_TABLE);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('anchor', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('subject_type', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('subject_id', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('capabilities', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => 'read']);
		$table->addColumn('label', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('password_hash', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('created', Types::DATETIME_MUTABLE, ['notnull' => true]);
		// NOT NULL on purpose: a link without an end date is a door nobody closes.
		$table->addColumn('expires_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
		$table->addColumn('revoked_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
		$table->addColumn('disabled', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
		$table->addColumn('last_used_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
		$table->addColumn('use_count', Types::BIGINT, ['notnull' => true, 'default' => 0]);

		$table->setPrimaryKey(['id']);
		// The anchor is the secret and the only lookup the public endpoint makes.
		$table->addUniqueIndex(['anchor'], 'or_alink_anchor');
		// The audit actor resolves back to its row.
		$table->addUniqueIndex(['uuid'], 'or_alink_uuid');
		// "What have I published?" and "what is open on this record?"
		$table->addIndex(['created_by'], 'or_alink_creator');
		$table->addIndex(['subject_type', 'subject_id'], 'or_alink_subject');

		return $schema;

	}//end changeSchema()
}//end class
