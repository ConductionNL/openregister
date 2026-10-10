<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An index on the audit trail for one reader's read history.
 *
 * "Recently opened" (the `_recent` lens) is read from the audit trail's `read`
 * rows: per user, distinct objects, ordered by the latest read. The query
 * filters on `user` and `action`, groups by `object_uuid` and takes the
 * maximum `created`, so an index in exactly that column order answers it
 * from the index alone. No earlier index leads with `user`.
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
 * @spec openspec/changes/read-history-on-audit-trail/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the (user, action, object_uuid, created) index to the audit trail.
 *
 * @spec openspec/changes/read-history-on-audit-trail/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
 */
class Version1Date20261009100000 extends SimpleMigrationStep {

	/**
	 * The audit trail table.
	 */
	private const TABLE = 'openregister_audit_trails';

	/**
	 * The index this migration adds.
	 */
	private const INDEX = 'or_audit_user_read_hist';

	/**
	 * The filter columns first, then the group column, then the sort column.
	 */
	private const COLUMNS = ['user', 'action', 'object_uuid', 'created'];

	/**
	 * Add the index unless it is already there.
	 *
	 * Add-only: nothing existing is altered, renamed or dropped.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, changed or not.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/read-history-on-audit-trail/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return $schema;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasIndex(self::INDEX) === true) {
			return $schema;
		}

		foreach (self::COLUMNS as $column) {
			if ($table->hasColumn($column) === false) {
				return $schema;
			}
		}

		$table->addIndex(self::COLUMNS, self::INDEX);
		$output->info('Added index '.self::INDEX.' on '.self::TABLE.' (user, action, object_uuid, created)');

		return $schema;
	}//end changeSchema()
}//end class
