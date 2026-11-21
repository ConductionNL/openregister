<?php

/**
 * Audit category — `domain` or `administrative` on every audit row.
 *
 * Adds the nullable `category` column with an index, and backfills existing
 * rows by action: schema, register, organisation and settings actions are
 * administrative, everything else is domain. The column is outside the
 * canonical JSON the chain seals, so the chain verifies before and after the
 * backfill (history-schema-and-settings-edits-audited, REQ-HSA-011).
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCA\OpenRegister\Service\Audit\AuditCategory;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;

/**
 * Add and backfill `openregister_audit_trails.category`.
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
 */
class Version1Date20261114230015 extends TargetedMigrationStep {

	/**
	 * The audit table.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_audit_trails';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $connection Database connection
	 */
	public function __construct(
		private readonly IDBConnection $connection,
	) {
	}//end __construct()

	/**
	 * Add the column and its index.
	 *
	 * @param IOutput $output        Output for the migration process
	 * @param Closure $schemaClosure The schema closure
	 * @param array   $options       Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
		 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
	 */
	protected function changeTargetedSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE) === false) {
			return $schema;
		}

		$audit = $schema->getTable(self::TABLE);

		if ($audit->hasColumn('category') === false) {
			$audit->addColumn('category', Types::STRING, ['notnull' => false, 'length' => 32]);
		}

		if ($audit->hasIndex('or_audit_category_idx') === false) {
			$audit->addIndex(['category'], 'or_audit_category_idx');
		}

		return $schema;
	}//end changeTargetedSchema()

	/**
	 * Backfill the category of every row that has none, by action.
	 *
	 * @param IOutput $output        Output for the migration process
	 * @param Closure $schemaClosure The schema closure
	 * @param array   $options       Migration options
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
		 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
	 */
	protected function afterTargetedSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false || $schema->getTable(self::TABLE)->hasColumn('category') === false) {
			return;
		}

		$counts = $this->backfill();
		$output->info(
			sprintf('Audit category backfilled: %d administrative, %d domain row(s).', $counts[AuditCategory::ADMINISTRATIVE], $counts[AuditCategory::DOMAIN])
		);
	}//end afterTargetedSchemaChange()

	/**
	 * Set `category` on every row without one: administrative for the
	 * administrative action prefixes, domain for the rest.
	 *
	 * Only `category` is written, so neither `hash` nor any sealed field changes.
	 *
	 * @return array{administrative: int, domain: int} The rows updated per category.
	 *
	 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
	 */
	public function backfill(): array {
		$qb   = $this->connection->getQueryBuilder();
		$like = [];
		foreach (AuditCategory::ADMINISTRATIVE_PREFIXES as $prefix) {
			$like[] = $qb->expr()->like(
				'action',
				$qb->createNamedParameter($this->connection->escapeLikeParameter($prefix) . '%', IQueryBuilder::PARAM_STR)
			);
		}

		$qb->update(self::TABLE)
			->set('category', $qb->createNamedParameter(AuditCategory::ADMINISTRATIVE, IQueryBuilder::PARAM_STR))
			->where($qb->expr()->isNull('category'))
			->andWhere($qb->expr()->orX(...$like));
		$administrative = $qb->executeStatement();

		$rest = $this->connection->getQueryBuilder();
		$rest->update(self::TABLE)
			->set('category', $rest->createNamedParameter(AuditCategory::DOMAIN, IQueryBuilder::PARAM_STR))
			->where($rest->expr()->isNull('category'));
		$domain = $rest->executeStatement();

		return [AuditCategory::ADMINISTRATIVE => $administrative, AuditCategory::DOMAIN => $domain];
	}//end backfill()
}//end class
