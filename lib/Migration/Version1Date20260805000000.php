<?php

/**
 * Adds the `purged_at` tombstone column to the audit-trail table.
 *
 * Retention purges used to HARD-delete audit rows. The table carries a
 * `hash`/`previous_hash` SHA-256 chain walked in id order, so removing a row
 * mid-chain makes the FOLLOWING row fail verification — a lawful purge and a
 * tampering event produced the identical symptom, which is a forensic hole in
 * a system holding Dutch legal-retention data (or#2265).
 *
 * With this column a purge blanks the payload and stamps a timestamp instead
 * of deleting the row, so the chain link survives and `verifyChain()` can
 * report a declared tombstone rather than a break.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `purged_at` to `openregister_audit_trails`.
 */
class Version1Date20260805000000 extends SimpleMigrationStep {
	/**
	 * Add the nullable `purged_at` column and an index for the purge sweep.
	 *
	 * Nullable with no default: `NULL` means "intact row", which is what every
	 * existing row is. No backfill is possible or wanted — rows purged before
	 * this migration were physically deleted and cannot be reconstructed.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
	 * @param array $options Migration options.
	 *
	 * @return ISchemaWrapper|null The modified schema, or null when unchanged.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_audit_trails') === false) {
			$output->info('openregister_audit_trails does not exist yet; nothing to alter.');
			return null;
		}

		$table = $schema->getTable('openregister_audit_trails');
		$changed = false;

		if ($table->hasColumn('purged_at') === false) {
			$table->addColumn(
				'purged_at',
				Types::DATETIME,
				[
					'notnull' => false,
					'default' => null,
				]
			);
			$changed = true;
			$output->info('Added openregister_audit_trails.purged_at (retention tombstone marker).');
		}

		// The purge sweep and the "is this chain clean?" report both filter on
		// this column across a table that is now expected to grow for years.
		if ($table->hasIndex('or_audit_purged_at_idx') === false) {
			$table->addIndex(['purged_at'], 'or_audit_purged_at_idx');
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()
}//end class
