<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Decisions as their own objects (reviewer-owns-their-decisions, decision 197):
 * a table `openregister_decisions` holding a verdict on a subject, who decided
 * it, when and a note, and a `decision_uuid` column on
 * `openregister_entity_relations` by which a relation links its current
 * decision.
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
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates `openregister_decisions` and links entity relations to it.
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
 */
class Version1Date20261011140000 extends SimpleMigrationStep {

	/**
	 * The decision table.
	 */
	public const TABLE = 'openregister_decisions';

	/**
	 * The relation table that gains the link.
	 */
	public const RELATIONS = 'openregister_entity_relations';

	/**
	 * Create the table and the link column when they are absent.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			$table = $schema->createTable(self::TABLE);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('subject_type', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('subject_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('verdict', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('decided_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('decided_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('note', Types::TEXT, ['notnull' => false]);
			$table->addColumn('supersedes', Types::STRING, ['notnull' => false, 'length' => 255]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['uuid'], 'or_decision_uuid');
			$table->addIndex(['subject_type', 'subject_id'], 'or_decision_subject');
			$table->addIndex(['decided_by'], 'or_decision_decided_by');
		}

		if ($schema->hasTable(self::RELATIONS) === true) {
			$relations = $schema->getTable(self::RELATIONS);
			if ($relations->hasColumn('decision_uuid') === false) {
				$relations->addColumn('decision_uuid', Types::STRING, ['notnull' => false, 'length' => 255]);
				$relations->addIndex(['decision_uuid'], 'or_entrel_decision');
			}
		}

		return $schema;
	}//end changeSchema()
}//end class
