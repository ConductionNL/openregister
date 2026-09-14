<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rule run log and its summary: why a rule did or did not fire.
 *
 * TWO TABLES, ON PURPOSE. A rule that fires on every object save writes a row
 * per save, so the detail log is a retention subject and is pruned by the daily
 * pass. The inventory still has to answer "when did this last run, and what did
 * it last say" after the detail is gone, so the summary is its own row per rule,
 * updated in place and never pruned. One table with a flag would make the prune
 * a conditional delete over the hot index, which is how a prune starts skipping
 * rows nobody notices.
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
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the rule run log and the per-rule summary.
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
class Version1Date20260914210000 extends SimpleMigrationStep {

	/**
	 * The detail log: one row per evaluation, pruned by retention.
	 */
	private const RUNS = 'openregister_rule_runs';

	/**
	 * The summary: one row per rule, updated in place and never pruned.
	 */
	private const SUMMARIES = 'openregister_rule_summaries';

	/**
	 * Create both tables when absent.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable(self::RUNS) === false) {
			$table = $schema->createTable(self::RUNS);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			// The derived rule id: kind, schema slug and the rule's own key.
			$table->addColumn('rule_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('schema_slug', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('register_slug', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('object_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
			$table->addColumn('verdict', Types::STRING, ['notnull' => true, 'length' => 16]);
			// The operand that decided, and the value it read, per D-2.
			$table->addColumn('operand', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('operand_value', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('message', Types::TEXT, ['notnull' => false]);
			$table->addColumn('actor', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created', Types::DATETIME_MUTABLE, ['notnull' => true]);

			$table->setPrimaryKey(['id']);
			// The run log read: one rule, newest first, optionally by verdict.
			$table->addIndex(['rule_id', 'created'], 'or_rulerun_rule_idx');
			$table->addIndex(['rule_id', 'verdict'], 'or_rulerun_verdict_idx');
			// The daily prune deletes by age alone.
			$table->addIndex(['created'], 'or_rulerun_created_idx');
			// An object's own rule history, for a detail surface.
			$table->addIndex(['object_uuid'], 'or_rulerun_object_idx');

			$output->info('Created openregister_rule_runs (the rule evaluation log).');
			$changed = true;
		}

		if ($schema->hasTable(self::SUMMARIES) === false) {
			$summary = $schema->createTable(self::SUMMARIES);
			$summary->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$summary->addColumn('rule_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$summary->addColumn('schema_slug', Types::STRING, ['notnull' => true, 'length' => 255]);
			$summary->addColumn('last_run', Types::DATETIME_MUTABLE, ['notnull' => false]);
			$summary->addColumn('last_verdict', Types::STRING, ['notnull' => false, 'length' => 16]);
			$summary->addColumn('last_error', Types::TEXT, ['notnull' => false]);
			$summary->addColumn('last_error_at', Types::DATETIME_MUTABLE, ['notnull' => false]);

			$summary->setPrimaryKey(['id']);
			// One summary per rule: recording twice updates, never duplicates.
			$summary->addUniqueIndex(['rule_id'], 'or_rulesum_rule_idx');
			// The inventory loads every summary for one schema in one read.
			$summary->addIndex(['schema_slug'], 'or_rulesum_schema_idx');

			$output->info('Created openregister_rule_summaries (last run and last error per rule).');
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()
}//end class
