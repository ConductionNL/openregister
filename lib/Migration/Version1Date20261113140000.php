<?php

/**
 * The former_slugs column on schemas.
 *
 * A renamed schema keeps the slugs it answered to before, so an old API path
 * and an app re-import still find it.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the nullable `former_slugs` column to openregister_schemas.
 *
 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md
 */
class Version1Date20261113140000 extends SimpleMigrationStep {

	/**
	 * The schemas table.
	 */
	private const TABLE = 'openregister_schemas';

	/**
	 * The column holding the former slugs.
	 */
	private const COLUMN = 'former_slugs';

	/**
	 * Add the column when it is absent.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, also when nothing changed (a null drops the shared snapshot).
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md#requirement-a-record-type-can-be-renamed-without-breaking-its-links
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE) === false) {
			return $schema;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn(self::COLUMN) === true) {
			return $schema;
		}

		// TEXT, like every other JSON field on this table: the entity encodes
		// and decodes it. Nullable: an existing schema was never renamed.
		$table->addColumn(
			self::COLUMN,
			Types::TEXT,
			[
				'notnull' => false,
				'default' => null,
				'comment' => 'JSON list of the slugs this schema had before a rename',
			]
		);

		$output->info('schema renames: added the former_slugs column to ' . self::TABLE);

		return $schema;

	}//end changeSchema()
}//end class
