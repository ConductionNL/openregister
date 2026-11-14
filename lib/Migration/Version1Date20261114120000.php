<?php

/**
 * Keep an import run as a durable record: its lineage, its row outcomes and its prune.
 *
 * The import preview already is the run record (the file, its fingerprint,
 * the mapping, the policy, the actor, the moment and the counts). Four columns
 * make it one that survives itself:
 *
 * - `openregister_import_previews.cause_run`: the uuid of the run a retry was
 *   made from, so a retry is a new run naming the original (D-6).
 * - `openregister_import_previews.rows_pruned_at`: when the per-row detail was
 *   pruned, so a pruned run says so rather than reading as one with no rows (D-5).
 * - `openregister_import_preview_rows.source`: the row as it was submitted, so
 *   a failed row can be exported in that shape and loaded again.
 * - `openregister_import_preview_rows.outcome`: `written` or `failed` once a
 *   commit wrote the row or failed to.
 *
 * Idempotent: every column is added only while it is missing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the run lineage, the prune mark, the submitted row and the row outcome.
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md
 */
class Version1Date20261114120000 extends SimpleMigrationStep {
	private const PREVIEWS = 'openregister_import_previews';

	private const ROWS = 'openregister_import_preview_rows';

	/**
	 * Add the four columns.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper The schema, changed or not: a null return drops the shared snapshot.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md#requirement-an-import-leaves-a-durable-record-with-an-outcome-per-row-req-rcn-005
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable(self::PREVIEWS) === true) {
			$table = $schema->getTable(self::PREVIEWS);
			if ($table->hasColumn('cause_run') === false) {
				$table->addColumn('cause_run', Types::STRING, ['notnull' => false, 'length' => 36]);
				$table->addIndex(['cause_run'], 'idx_or_imprev_cause');
				$changed = true;
			}

			if ($table->hasColumn('rows_pruned_at') === false) {
				$table->addColumn('rows_pruned_at', Types::DATETIME, ['notnull' => false]);
				$changed = true;
			}
		}

		if ($schema->hasTable(self::ROWS) === true) {
			$table = $schema->getTable(self::ROWS);
			if ($table->hasColumn('source') === false) {
				$table->addColumn('source', Types::TEXT, ['notnull' => false]);
				$changed = true;
			}

			if ($table->hasColumn('outcome') === false) {
				$table->addColumn('outcome', Types::STRING, ['notnull' => false, 'length' => 16]);
				$changed = true;
			}
		}

		if ($changed === true) {
			$output->info('Added the import run lineage, prune mark, submitted row and row outcome columns');
		}

		return $schema;
	}//end changeSchema()
}//end class
