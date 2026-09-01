<?php

/**
 * Drop the orphaned openregister_realtime_events table.
 *
 * The DB-backed realtime change feed (RealtimeService / RealtimeEventListener /
 * RealtimeController, archived change 2026-05-01-realtime-updates) was removed
 * by the complete-live-updates change: it wrote a CloudEvent row on every
 * object lifecycle event but had zero consumers. Its create-migration
 * (Version1Date20260430000000) was deleted so fresh installs never get the
 * table; this step cleans up instances that ran development between May and
 * July 2026 and still carry the orphaned table (nothing writes to or reads
 * from it anymore). Dropping the table also drops its indexes
 * (idx_realtime_register_schema_id, idx_realtime_object_id,
 * idx_realtime_org_id). Idempotent: drops only when present.
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
 * @spec openspec/specs/realtime-updates/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop the orphaned openregister_realtime_events table (and its indexes).
 *
 * @spec openspec/specs/realtime-updates/spec.md
 */
class Version1Date20260714120000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_realtime_events') === false) {
			return null;
		}

		$schema->dropTable('openregister_realtime_events');

		return $schema;
	}//end changeSchema()
}//end class
