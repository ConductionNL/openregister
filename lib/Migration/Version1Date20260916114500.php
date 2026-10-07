<?php

/**
 * An index the operations console reads the notification queue through.
 *
 * The dispatch history already carries three indexes, and every one of them
 * starts at a thing the reader already knows: the object, the rule, the
 * recipient. An operations console knows none of those. It asks "how did the
 * last day of dispatches come out", which is a grouping by `status` inside a
 * window on `dispatched_at`, and against the shipped indexes that is a scan
 * of a table which grows by one row per notification per channel.
 *
 * `(status, dispatched_at)` is the pair, in that order: the equality column
 * first so the window is a range scan inside one status rather than a filter
 * applied after the fact (ADR-009).
 *
 * Idempotent: the index is added only when absent, and the table only when it
 * is already there, so an instance that has not yet reached the history
 * migration passes through untouched.
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
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Index the notification dispatch history by outcome and moment.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */
class Version1Date20260916114500 extends SimpleMigrationStep {

	/**
	 * The table the console groups.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_notification_history';

	/**
	 * The index name, inside Nextcloud's 30-character ceiling.
	 *
	 * @var string
	 */
	private const INDEX = 'or_notif_hist_status_idx';

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE) === false) {
			return $schema;
		}

		$table = $schema->getTable(self::TABLE);

		if ($table->hasIndex(self::INDEX) === true) {
			return $schema;
		}

		$table->addIndex(['status', 'dispatched_at'], self::INDEX);
		$output->info('Indexed '.self::TABLE.' by status and dispatch moment');

		return $schema;
	}//end changeSchema()
}//end class
