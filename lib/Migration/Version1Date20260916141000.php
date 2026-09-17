<?php

/**
 * Note edit history — the table that keeps what a note said before.
 *
 * A note is a Nextcloud comment, and a comment has no history: an edit
 * overwrites the message column. `openregister_note_versions` holds every
 * prior text beside the comment, keyed on the comment id, with the actor the
 * text was attributed to, the user who replaced it and the time. The comments
 * schema is untouched.
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
 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the note versions table.
 *
 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
 */
class Version1Date20260916141000 extends SimpleMigrationStep {

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_note_versions') === true) {
			return $schema;
		}

		$table = $schema->createTable('openregister_note_versions');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		// The Nextcloud comment the version belongs to. Comment ids are
		// strings on the wire and integers in storage; kept as an integer here
		// so the index is the same shape as the comments table's own key.
		$table->addColumn('comment_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('message', Types::TEXT, ['notnull' => false]);
		// The actor the replaced text was attributed to, and its type: a note
		// can be written by an access link rather than by an account, and a
		// version that forgot that would name the wrong author.
		$table->addColumn('author', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('author_type', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('edited_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('edited_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'idx_or_notever_uuid');
		$table->addIndex(['comment_id'], 'idx_or_notever_comment');
		$table->addIndex(['comment_id', 'edited_at'], 'idx_or_notever_when');

		$output->info('Created openregister_note_versions table');

		return $schema;

	}//end changeSchema()
}//end class
