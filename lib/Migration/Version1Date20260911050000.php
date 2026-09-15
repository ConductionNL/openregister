<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Give an organisation a legal name beside the name it is called by.
 *
 * dossiq kept its tenants in a `tenant` schema of its own, beside this table,
 * and is moving them onto Organisation. Measured field by field, the tenant's
 * identity maps onto columns that already exist: `slug`, `name` for its display
 * name, `kvk` for its KvK number. One property had nowhere to go, and it is the
 * one this migration adds: the registered legal name (statutaire naam), which a
 * welcome letter, a contract or an invoice addresses and which is routinely
 * different from the name a list shows.
 *
 * Nullable with NO default and no backfill from `name`. A copy of `name` written
 * here would read as a legal name someone had verified, and nothing afterwards
 * could tell the two apart.
 *
 * @category  Migration
 * @package   OCA\OpenRegister\Migration
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the `legal_name` column to `openregister_organisations`.
 */
final class Version1Date20260911050000 extends SimpleMigrationStep {

	/**
	 * The table this migration widens.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_organisations';

	/**
	 * The column this migration adds.
	 *
	 * @var string
	 */
	private const COLUMN = 'legal_name';

	/**
	 * Add the column when it is absent.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema closure.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/* @var ISchemaWrapper $schema The schema wrapper. */
		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE) === false) {
			$output->warning(message: 'openregister_organisations is absent; skipping the legal_name column');

			return null;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn(self::COLUMN) === true) {
			return null;
		}

		$table->addColumn(
			self::COLUMN,
			Types::STRING,
			[
				'notnull' => false,
				'length' => 255,
				'comment' => 'The name the organisation is registered under (statutaire naam). '
					. 'Identity only, never a key: nothing may match, merge or scope on it.',
			]
		);

		$output->info(message: 'openregister_organisations: added ' . self::COLUMN);

		return $schema;
	}//end changeSchema()

}//end class
