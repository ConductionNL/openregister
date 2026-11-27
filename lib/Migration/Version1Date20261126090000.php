<?php

/**
 * Add `mismatch_at` and `mismatch_sha256` to openregister_file_digests.
 *
 * A published file found not to match its digest is recorded in the audit
 * trail as a JSON payload, which a read-only SQL probe cannot join on. The two
 * nullable columns mark the record itself, so the operations console can list
 * the published files whose bytes changed. Existing rows keep null: no
 * mismatch has been found for them yet.
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
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;

/**
 * Add the nullable mismatch columns to openregister_file_digests.
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
 */
class Version1Date20261126090000 extends TargetedMigrationStep {

	/**
	 * The table the columns are added to.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_file_digests';

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, changed or not.
	 *
	 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
	 */
	protected function changeTargetedSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return $schema;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn('mismatch_at') === false) {
			$table->addColumn('mismatch_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
			$output->info('Added mismatch_at to '.self::TABLE);
		}

		if ($table->hasColumn('mismatch_sha256') === false) {
			$table->addColumn('mismatch_sha256', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
			$output->info('Added mismatch_sha256 to '.self::TABLE);
		}

		return $schema;
	}//end changeTargetedSchema()
}//end class
