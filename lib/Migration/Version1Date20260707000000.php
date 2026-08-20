<?php

/**
 * Hot-path index for audit-trail register/schema queries.
 *
 * The audit trail (`openregister_audit_trails`) is append-only and grows
 * unboundedly. Every register-detail view, dashboard statistic, and audit
 * history listing filters `WHERE register = ? [AND schema = ?]` (see
 * `AuditTrailMapper::getStatistics()` and the history queries), often with a
 * `created` date-range, yet the table only carried indexes on `user`, `uuid`,
 * `hash`, `processing_activity_id`, and `import_job_id` — so those filters
 * did a full table scan that worsened with every mutation ever recorded.
 *
 * This migration adds a composite `(register, schema, created)` index so the
 * scoped statistics/history reads are index-backed. Idempotent: only adds the
 * index when the table exists and the index is absent.
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
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the (register, schema, created) composite index to openregister_audit_trails.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
class Version1Date20260707000000 extends SimpleMigrationStep {
	/**
	 * Add the audit-trail register/schema/created composite index.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_audit_trails') === false) {
			return null;
		}

		$table = $schema->getTable('openregister_audit_trails');

		if ($table->hasIndex('idx_audit_register_schema') === true) {
			return null;
		}

		$table->addIndex(['register', 'schema', 'created'], 'idx_audit_register_schema');

		$output->info('Added idx_audit_register_schema on openregister_audit_trails (register, schema, created)');

		return $schema;
	}//end changeSchema()
}//end class
