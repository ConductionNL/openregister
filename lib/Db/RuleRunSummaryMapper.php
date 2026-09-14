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
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
class RuleRunSummaryMapper extends QBMapper {

	/**
	 * How long a repeat of the same verdict may go unwritten.
	 *
	 * @var integer
	 */
	public const THROTTLE_SECONDS = 60;

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
	 * THROTTLED, BECAUSE THIS RUNS ON EVERY SAVE. A rule that fires on every
	 * object save would otherwise cost one UPDATE per save per rule, and an
	 * import of four hundred thousand objects would spend most of its time
	 * rewriting a timestamp nobody is watching change. So a repeat of the same
	 * verdict inside the throttle window is not written: `lastRun` is then
	 * accurate to the window rather than to the second, which is the resolution
	 * "has this rule run in ninety days" actually needs. A CHANGED verdict and
	 * an error are always written, because those are the two things an
	 * administrator is reading the summary for.
	 *
	 * @param string $ruleId The derived rule id.
	 * @param string $schemaSlug The schema the rule is declared on.
	 * @param string $verdict The verdict reached.
	 * @param DateTime $moment When the evaluation happened.
	 * @param string|null $error The message, when the verdict was an error.
	 *
	 * @return RuleRunSummary The stored summary, written or not.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function record(
		string $ruleId,
		string $schemaSlug,
		string $verdict,
		DateTime $moment,
		?string $error = null,
	): RuleRunSummary {
		$summary = $this->findByRule(ruleId: $ruleId);
		if ($summary === null) {
			$summary = new RuleRunSummary();
			$summary->setRuleId($ruleId);
			$summary->setSchemaSlug($schemaSlug);
		}

		$isError = ($error !== null && $error !== '');
		if ($this->worthWriting(summary: $summary, verdict: $verdict, moment: $moment, isError: $isError) === false) {
			return $summary;
		}

		$summary->setSchemaSlug($schemaSlug);
		$summary->setLastRun($moment);
		$summary->setLastVerdict($verdict);

		if ($isError === true) {
			$summary->setLastError($error);
			$summary->setLastErrorAt($moment);
		}

		if ($summary->getId() === null) {
			return $this->insert(entity: $summary);
		}

		return $this->update(entity: $summary);

	}//end record()

	/**
	 * Whether this evaluation changes anything an administrator reads.
	 *
	 * @param RuleRunSummary $summary The summary as it stands.
	 * @param string $verdict The verdict reached.
	 * @param DateTime $moment When the evaluation happened.
	 * @param bool $isError Whether the verdict carries an error message.
	 *
	 * @return bool True when the row should be written.
	 *
	 * PUBLIC so the throttle is testable on its own. It is a rule about which
	 * writes may be dropped, and a rule about dropping writes that can only be
	 * exercised through a live database is a rule nobody checks.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag distinguishes the one case
	 *   that always writes from the one that may be throttled; splitting it would be two
	 *   methods with the same body and one line different.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function worthWriting(RuleRunSummary $summary, string $verdict, DateTime $moment, bool $isError): bool {
		if ($summary->getId() === null || $isError === true) {
			return true;
		}

		if ((string)$summary->getLastVerdict() !== $verdict) {
			return true;
		}

		$lastRun = $summary->getLastRun();
		if ($lastRun === null) {
			return true;
		}

		return (($moment->getTimestamp() - $lastRun->getTimestamp()) >= self::THROTTLE_SECONDS);

	}//end worthWriting()
}//end class
