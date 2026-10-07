<?php

/**
 * A saved view can say something when its count crosses a line.
 *
 * Adds `alert`, the declaration, and `alert_state`, the two facts the sweep
 * has to remember between passes: whether it has already said so, and when it
 * last looked.
 *
 * 🔴 BOTH NULLABLE, AND NULL MEANS NO ALERT. Every existing view keeps
 * behaving exactly as it did: a migration that armed alerts would start sending
 * notifications nobody asked for, from a threshold nobody set.
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
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the alert and its state to saved views.
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
 */
class Version1Date20260918233000 extends SimpleMigrationStep {

	/**
	 * The views table.
	 *
	 * @var string
	 */
	private const TABLE_VIEWS = 'openregister_views';

	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output The migration output.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper The changed schema.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable(tableName: self::TABLE_VIEWS) === false) {
			return $schema;
		}

		$views = $schema->getTable(self::TABLE_VIEWS);

		if ($views->hasColumn('alert') === false) {
			$views->addColumn('alert', Types::JSON, ['notnull' => false]);
			$output->info('Added alert to openregister_views');
		}

		if ($views->hasColumn('alert_state') === false) {
			$views->addColumn('alert_state', Types::JSON, ['notnull' => false]);
		}

		// The sweep orders by this to keep a watermark, so no view starves
		// behind a busier one.
		if ($views->hasColumn('alert_evaluated_at') === false) {
			$views->addColumn('alert_evaluated_at', Types::DATETIME, ['notnull' => false]);
			$views->addIndex(['alert_evaluated_at'], 'idx_or_view_alert_due');
		}

		return $schema;
	}//end changeSchema()
}//end class
