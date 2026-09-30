<?php

/**
 * The draft column on schemas.
 *
 * A schema can hold one pending edit of its definition beside the published
 * one. Validation never reads it; publishing applies it through the normal
 * update and clears it.
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
 * @spec openspec/changes/modelling-schema-draft/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the nullable `draft` column to openregister_schemas.
 *
 * @spec openspec/changes/modelling-schema-draft/specs/runtime-schema-api/spec.md
 */
class Version1Date20260930110000 extends SimpleMigrationStep {

	/**
	 * The schemas table.
	 */
	private const TABLE = 'openregister_schemas';

	/**
	 * The column holding the pending definition.
	 */
	private const COLUMN = 'draft';

	/**
	 * Add the column when it is absent.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/modelling-schema-draft/specs/runtime-schema-api/spec.md#requirement-req-sdraft-001-a-schema-edit-can-be-held-as-a-draft-until-it-is-published
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE) === false) {
			return null;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn(self::COLUMN) === true) {
			return null;
		}

		// TEXT, like every other JSON field on this table: the entity encodes
		// and decodes it. Nullable: an existing schema has no draft.
		$table->addColumn(
			self::COLUMN,
			Types::TEXT,
			[
				'notnull' => false,
				'default' => null,
				'comment' => 'JSON pending edit of the definition; validation reads the published one',
			]
		);

		$output->info('schema drafts: added the draft column to ' . self::TABLE);

		return $schema;

	}//end changeSchema()
}//end class
