<?php

/**
 * Timeline entries become records, with kinds, references and canned text.
 *
 * Creates the five tables this capability rests on:
 *
 *  - openregister_timeline_entries: one row per timeline entry. The entry text
 *    itself still lives in the Nextcloud comment that backs a note; this row
 *    is the entry's RECORD and its INDEX, carrying the stable uuid, the object
 *    it hangs on, its kind and declared fields, its author, its visibility,
 *    the pin, the follow-up state, the raw inbound source and the detected
 *    language. Search reads this table, which is why the message is copied
 *    onto it (D-1).
 *  - openregister_timeline_kinds: an administered entry kind and the
 *    properties entries of that kind carry (D-2).
 *  - openregister_reference_patterns: an administered short-code pattern and
 *    the target it resolves to (D-6).
 *  - openregister_entry_references: one recorded reference, readable from both
 *    ends, rewritten whenever the text that produced it is rewritten (D-6).
 *  - openregister_text_blocks: an administered canned text block, scoped to a
 *    register, a schema or a group.
 *
 * Idempotent: each table is created only when absent.
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
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the timeline entry record, kind, reference and text block tables.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class Version1Date20260915120000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		$this->entries(schema: $schema, output: $output);
		$this->kinds(schema: $schema, output: $output);
		$this->references(schema: $schema, output: $output);
		$this->textBlocks(schema: $schema, output: $output);

		return $schema;
	}//end changeSchema()

	/**
	 * The entry record table.
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 * @param IOutput        $output Migration output.
	 *
	 * @return void
	 */
	private function entries(ISchemaWrapper $schema, IOutput $output): void {
		if ($schema->hasTable('openregister_timeline_entries') === true) {
			return;
		}

		$table = $schema->createTable('openregister_timeline_entries');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('register', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('schema', Types::STRING, ['notnull' => false, 'length' => 255]);
		// The comment that carries the text. Null for an entry written by a
		// source that is not a note, so the record does not depend on one.
		$table->addColumn('comment_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('kind', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('author', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('visibility', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'internal']);
		$table->addColumn('message', Types::TEXT, ['notnull' => false]);
		$table->addColumn('fields', Types::TEXT, ['notnull' => false]);
		$table->addColumn('follow_up', Types::STRING, ['notnull' => false, 'length' => 16]);
		$table->addColumn('closed_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('closed_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('pinned', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('pinned_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('pinned_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('language', Types::STRING, ['notnull' => false, 'length' => 16]);
		$table->addColumn('raw_source', Types::TEXT, ['notnull' => false]);
		$table->addColumn('raw_headers', Types::TEXT, ['notnull' => false]);
		// The sibling entries a multi-object note produced, so each entry can
		// name the others without a second table.
		$table->addColumn('siblings', Types::TEXT, ['notnull' => false]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'idx_or_tlentry_uuid');
		$table->addUniqueIndex(['comment_id'], 'idx_or_tlentry_comment');
		// The timeline read: pinned first, then newest, for one object.
		$table->addIndex(['object_uuid', 'pinned', 'created'], 'idx_or_tlentry_object');
		// The search read: the visibility filter is a query condition, never a
		// filter on a page that was already built (D-1).
		$table->addIndex(['visibility', 'created'], 'idx_or_tlentry_vis');
		$table->addIndex(['kind', 'follow_up'], 'idx_or_tlentry_followup');
		$table->addIndex(['author'], 'idx_or_tlentry_author');

		$output->info('Created openregister_timeline_entries table');
	}//end entries()

	/**
	 * The administered entry kind table.
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 * @param IOutput        $output Migration output.
	 *
	 * @return void
	 */
	private function kinds(ISchemaWrapper $schema, IOutput $output): void {
		if ($schema->hasTable('openregister_timeline_kinds') === true) {
			return;
		}

		$table = $schema->createTable('openregister_timeline_kinds');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('description', Types::TEXT, ['notnull' => false]);
		// A JSON Schema `properties` map, validated the way object properties
		// are validated, so a kind is not a second schema language.
		$table->addColumn('properties', Types::TEXT, ['notnull' => false]);
		$table->addColumn('required', Types::TEXT, ['notnull' => false]);
		$table->addColumn('follow_up', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('register', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('schema', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['slug'], 'idx_or_tlkind_slug');
		$table->addIndex(['register', 'schema'], 'idx_or_tlkind_scope');

		$output->info('Created openregister_timeline_kinds table');
	}//end kinds()

	/**
	 * The reference pattern and the recorded reference tables.
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 * @param IOutput        $output Migration output.
	 *
	 * @return void
	 */
	private function references(ISchemaWrapper $schema, IOutput $output): void {
		if ($schema->hasTable('openregister_reference_patterns') === false) {
			$table = $schema->createTable('openregister_reference_patterns');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
			$table->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('pattern', Types::STRING, ['notnull' => true, 'length' => 512]);
			$table->addColumn('register', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('schema', Types::STRING, ['notnull' => false, 'length' => 255]);
			// The property the matched code is looked up on, and the url the
			// link points at. `{code}` is substituted in both.
			$table->addColumn('target_property', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('url_template', Types::STRING, ['notnull' => false, 'length' => 512]);
			$table->addColumn('enabled', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['slug'], 'idx_or_refpat_slug');
			$table->addIndex(['enabled'], 'idx_or_refpat_enabled');

			$output->info('Created openregister_reference_patterns table');
		}//end if

		if ($schema->hasTable('openregister_entry_references') === true) {
			return;
		}

		$table = $schema->createTable('openregister_entry_references');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('entry_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('source_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('target_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('code', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('pattern_slug', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('url', Types::STRING, ['notnull' => false, 'length' => 512]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['entry_uuid'], 'idx_or_entryref_entry');
		// Both ends are read: "what does this note point at" and "which zaken
		// mention this besluit" are the same table, queried from either side.
		$table->addIndex(['source_uuid'], 'idx_or_entryref_source');
		$table->addIndex(['target_uuid'], 'idx_or_entryref_target');

		$output->info('Created openregister_entry_references table');
	}//end references()

	/**
	 * The administered canned text block table.
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 * @param IOutput        $output Migration output.
	 *
	 * @return void
	 */
	private function textBlocks(ISchemaWrapper $schema, IOutput $output): void {
		if ($schema->hasTable('openregister_text_blocks') === true) {
			return;
		}

		$table = $schema->createTable('openregister_text_blocks');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('body', Types::TEXT, ['notnull' => false]);
		$table->addColumn('register', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('schema', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('group_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['slug'], 'idx_or_textblock_slug');
		$table->addIndex(['register', 'schema'], 'idx_or_textblock_scope');
		$table->addIndex(['group_id'], 'idx_or_textblock_group');

		$output->info('Created openregister_text_blocks table');
	}//end textBlocks()
}//end class
