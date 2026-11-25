<?php

/**
 * Add a nullable `archive_member` to openregister_entity_relations.
 *
 * An entity found in a member of a zip attachment is stored against the
 * archive file; `archive_member` names the file inside the archive it was found in,
 * copied from the chunk's position reference, so a relation says where the
 * finding is without a join to its chunk. Existing rows keep null.
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
 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md#requirement-each-member-is-extracted-and-searchable-on-its-own-req-ams-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;

/**
 * Add nullable `archive_member` to openregister_entity_relations.
 *
 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md#requirement-each-member-is-extracted-and-searchable-on-its-own-req-ams-002
 */
class Version1Date20261125013000 extends TargetedMigrationStep {

	/**
	 * The longest member path kept; a longer one is cut when it is stored.
	 *
	 * @var integer
	 */
	public const MEMBER_LENGTH = 1024;

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, changed or not.
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md#requirement-each-member-is-extracted-and-searchable-on-its-own-req-ams-002
	 */
	protected function changeTargetedSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();
		if ($schema->hasTable('openregister_entity_relations') === false) {
			return $schema;
		}

		$table = $schema->getTable('openregister_entity_relations');
		if ($table->hasColumn('archive_member') === false) {
			$table->addColumn('archive_member', Types::STRING, ['notnull' => false, 'length' => self::MEMBER_LENGTH, 'default' => null]);
			$output->info('Added archive_member to openregister_entity_relations');
		}

		return $schema;
	}//end changeTargetedSchema()
}//end class
