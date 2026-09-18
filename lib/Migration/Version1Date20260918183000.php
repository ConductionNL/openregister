<?php

/**
 * A saved view can be shared with a group.
 *
 * Adds `shared_with` to `openregister_views`. A view had `is_public`,
 * `is_default` and `favored_by` and nothing in between: it was private or it
 * was everyone's, and a department could not have a view of its own (ledger
 * row 9.4).
 *
 * 🔑 JSON AND NOT A JOIN TABLE. A share is `{group, mode}` and a view has a
 * handful of them; the only question ever asked of it is "which views may this
 * caller see", and that question is answered over the whole view list, which is
 * tens of rows per organisation rather than millions. A join table would buy an
 * indexed predicate for a scan nobody notices and cost a second table to keep
 * in step with the entity's own JSON fields.
 *
 * Nullable with no back-fill: a view written before this change is shared with
 * nobody, which is what it meant, and an empty list would say the same thing
 * less clearly.
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
 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the group shares to a saved view.
 *
 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
 */
class Version1Date20260918183000 extends SimpleMigrationStep {

	/**
	 * The views table.
	 *
	 * @var string
	 */
	private const TABLE_VIEWS = 'openregister_views';

	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper The schema, changed or not.
	 *
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		// 🔑 THE SCHEMA COMES BACK EVEN WHEN THERE IS NOTHING TO DO. Returning
		// null drops the shared snapshot and makes the NEXT migration
		// re-introspect the whole database; `SchemaReuseHygieneTest` refuses it
		// for exactly that reason.
		if ($schema->hasTable(tableName: self::TABLE_VIEWS) === false) {
			return $schema;
		}

		$table = $schema->getTable(tableName: self::TABLE_VIEWS);

		if ($table->hasColumn('shared_with') === false) {
			$table->addColumn('shared_with', Types::TEXT, ['notnull' => false]);
		}

		return $schema;
	}//end changeSchema()
}//end class
