<?php

/**
 * RuleRunSummaryMapper: the per-rule summary the prune never touches.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Class RuleRunSummaryMapper.
 *
 * @method RuleRunSummary insert(Entity $entity)
 * @method RuleRunSummary update(Entity $entity)
 * @method RuleRunSummary delete(Entity $entity)
 *
 * @template-extends QBMapper<RuleRunSummary>
 *
 * @psalm-suppress PossiblyUnusedMethod
 */
class RuleRunSummaryMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_rule_summaries',
			entityClass: RuleRunSummary::class
		);

	}//end __construct()

	/**
	 * One rule's summary, when it has ever been evaluated.
	 *
	 * @param string $ruleId The derived rule id.
	 *
	 * @return RuleRunSummary|null The summary, or null when the rule has never run.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function findByRule(string $ruleId): ?RuleRunSummary {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId)))
			->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}

	}//end findByRule()

	/**
	 * Every summary for one schema, keyed by rule id.
	 *
	 * The inventory renders every rule on a schema at once, so it loads every
	 * summary in ONE read rather than one per entry.
	 *
	 * @param string $schemaSlug The schema's slug.
	 *
	 * @return array<string, RuleRunSummary> Summaries keyed by rule id.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function findBySchema(string $schemaSlug): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('schema_slug', $qb->createNamedParameter($schemaSlug)));

		$keyed = [];
		foreach ($this->findEntities(query: $qb) as $summary) {
			$keyed[(string)$summary->getRuleId()] = $summary;
		}

		return $keyed;

	}//end findBySchema()

	/**
	 * Record one evaluation against a rule's summary.
	 *
	 * Creates the row on the rule's first evaluation and updates it after. An
	 * error keeps its own pair of columns, so the last error survives a
	 * thousand successful runs and is still there when an administrator asks
	 * why a rule stopped being trusted.
	 *
	 * @param string $ruleId The derived rule id.
	 * @param string $schemaSlug The schema the rule is declared on.
	 * @param string $verdict The verdict reached.
	 * @param DateTime $at When the evaluation happened.
	 * @param string|null $error The message, when the verdict was an error.
	 *
	 * @return RuleRunSummary The stored summary.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function record(
		string $ruleId,
		string $schemaSlug,
		string $verdict,
		DateTime $at,
		?string $error = null,
	): RuleRunSummary {
		$summary = $this->findByRule(ruleId: $ruleId);
		if ($summary === null) {
			$summary = new RuleRunSummary();
			$summary->setRuleId($ruleId);
			$summary->setSchemaSlug($schemaSlug);
			$summary->setRuns(0);
		}

		$summary->setSchemaSlug($schemaSlug);
		$summary->setLastRun($at);
		$summary->setLastVerdict($verdict);
		$summary->setRuns(((int)$summary->getRuns() + 1));

		if ($error !== null && $error !== '') {
			$summary->setLastError($error);
			$summary->setLastErrorAt($at);
		}

		if ($summary->getId() === null) {
			return $this->insert($summary);
		}

		return $this->update($summary);

	}//end record()
}//end class
