<?php

/**
 * An explicit redaction decision on every entity relation.
 *
 * `openregister_entity_relations` gains `redaction_decision` (`undecided`,
 * `redact`, `release`), `decided_by` and `decided_at`. Existing rows are
 * backfilled from `skip_anonymization`: skipped rows were released, the rest
 * redacted; nobody is named as having decided them.
 *
 * Idempotent: columns are added only when absent, and the backfill only
 * touches rows still `undecided`.
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
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds and backfills the redaction decision columns.
 */
class Version1Date20261114100100 extends SimpleMigrationStep {

	private const TABLE = 'openregister_entity_relations';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $connection The database connection, for the backfill.
	 */
	public function __construct(private readonly IDBConnection $connection) {
	}//end __construct()

	/**
	 * Add the decision columns.
	 *
	 * @param IOutput $output        The migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array   $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.1
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return null;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn('redaction_decision') === false) {
			$table->addColumn('redaction_decision', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'undecided']);
		}

		if ($table->hasColumn('decided_by') === false) {
			$table->addColumn('decided_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		}

		if ($table->hasColumn('decided_at') === false) {
			$table->addColumn('decided_at', Types::DATETIME, ['notnull' => false]);
		}

		return $schema;

	}//end changeSchema()

	/**
	 * Backfill the decision of existing rows from `skip_anonymization`.
	 *
	 * @param IOutput $output        The migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array   $options       Migration options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.1
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if ($this->connection->tableExists(self::TABLE) === false) {
			return;
		}

		foreach (['release' => true, 'redact' => false] as $decision => $skipped) {
			$query = $this->connection->getQueryBuilder();
			$query->update(self::TABLE)
				->set('redaction_decision', $query->createNamedParameter($decision))
				->where($query->expr()->eq('redaction_decision', $query->createNamedParameter('undecided')))
				->andWhere($query->expr()->eq('skip_anonymization', $query->createNamedParameter($skipped, IQueryBuilder::PARAM_BOOL)))
				->andWhere($query->expr()->isNull('decided_at'));
			$rows = $query->executeStatement();
			$output->info(sprintf('Backfilled %d entity relations as %s', $rows, $decision));
		}

	}//end postSchemaChange()
}//end class
