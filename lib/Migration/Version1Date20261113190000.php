<?php

/**
 * OpenRegister Migration Version1Date20261113190000.
 *
 * Adds the client library to the caller record: a `client` column on
 * `openregister_api_calls`, and the unique index rebuilt over the five keys.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-client-libraries/specs/api-client-libraries/spec.md#requirement-the-caller-record-names-the-client-library
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The caller record learns which client library made a call.
 */
class Version1Date20261113190000 extends SimpleMigrationStep {

	/**
	 * The unique index name, rebuilt in place.
	 */
	private const UNIQUE_INDEX = 'idx_or_apicall_unique';

	/**
	 * Change the database schema.
	 *
	 * Existing rows get `''`: they were all made before a client could be
	 * named, which is what the empty string means.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, changed or not.
	 *
	 * @spec openspec/changes/api-client-libraries/specs/api-client-libraries/spec.md#requirement-the-caller-record-names-the-client-library
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_api_calls') === false) {
			return null;
		}

		$table = $schema->getTable('openregister_api_calls');
		if ($table->hasColumn('client') === true) {
			return null;
		}

		$table->addColumn('client', Types::STRING, ['notnull' => true, 'length' => 48, 'default' => '']);

		// One row per caller, route, method, version AND client: two library
		// versions used by one caller on one route are two rows, which is the
		// question an administrator asks before a library major is retired.
		if ($table->hasIndex(self::UNIQUE_INDEX) === true) {
			$table->dropIndex(self::UNIQUE_INDEX);
		}

		$table->addUniqueIndex(
			['principal', 'route', 'method', 'api_version', 'client'],
			self::UNIQUE_INDEX
		);

		$output->info('Added client to openregister_api_calls');

		return $schema;
	}//end changeSchema()
}//end class
