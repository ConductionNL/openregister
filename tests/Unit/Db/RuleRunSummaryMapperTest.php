<?php

/**
 * Unit tests for RuleRunSummaryMapper: the throttle that decides whether an
 * evaluation is written, and the reads and writes of the summary table.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\RuleRun;
use OCA\OpenRegister\Db\RuleRunSummary;
use OCA\OpenRegister\Db\RuleRunSummary\RuleEvaluation;
use OCA\OpenRegister\Db\RuleRunSummaryMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the rule run summary mapper.
 */
#[CoversClass(RuleRunSummaryMapper::class)]
#[UsesClass(RuleRunSummary::class)]
#[UsesClass(RuleRun::class)]
#[UsesClass(RuleEvaluation::class)]
class RuleRunSummaryMapperTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * A mapper over a connection that hands out recording builders.
	 *
	 * @return RuleRunSummaryMapper The mapper.
	 */
	private function mapper(): RuleRunSummaryMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function (): IQueryBuilder {
				$qb = $this->recordingBuilder();
				$qb->method('getLastInsertId')->willReturn(42);
				return $qb;
			}
		);

		return new RuleRunSummaryMapper($db);
	}//end mapper()

	/**
	 * A stored summary.
	 *
	 * @param string $verdict The last verdict.
	 * @param string $lastRun When it last ran.
	 *
	 * @return RuleRunSummary The summary.
	 */
	private function stored(string $verdict, ?string $lastRun): RuleRunSummary {
		$summary = new RuleRunSummary();
		$summary->setId(7);
		$summary->setRuleId('rule-1');
		$summary->setLastVerdict($verdict);
		if ($lastRun !== null) {
			$summary->setLastRun(new DateTime($lastRun));
		}

		$summary->resetUpdatedFields();
		return $summary;
	}//end stored()

	/**
	 * A rule run.
	 *
	 * @return RuleRun The run.
	 */
	private function ruleRun(): RuleRun {
		$run = new RuleRun();
		$run->setRuleId('rule-1');
		$run->setSchemaSlug('zaak');
		return $run;
	}//end ruleRun()

	/**
	 * A new summary, an error, a changed verdict and a summary that never ran are always written.
	 *
	 * @return void
	 */
	public function testWorthWritingWithoutThrottle(): void {
		$mapper = $this->mapper();
		$now    = new DateTime('2026-09-10T10:00:10+00:00');

		$this->assertTrue($mapper->worthWriting(new RuleRunSummary(), new RuleEvaluation('pass', $now)));
		$this->assertTrue($mapper->worthWriting($this->stored('pass', '2026-09-10T10:00:00+00:00'), new RuleEvaluation('pass', $now, 'boom')));
		$this->assertTrue($mapper->worthWriting($this->stored('fail', '2026-09-10T10:00:00+00:00'), new RuleEvaluation('pass', $now)));
		$this->assertTrue($mapper->worthWriting($this->stored('pass', null), new RuleEvaluation('pass', $now)));
	}//end testWorthWritingWithoutThrottle()

	/**
	 * The same verdict is written again only once the throttle has passed.
	 *
	 * @return void
	 */
	public function testTheSameVerdictIsThrottled(): void {
		$mapper  = $this->mapper();
		$summary = $this->stored('pass', '2026-09-10T10:00:00+00:00');

		$this->assertFalse($mapper->worthWriting($summary, new RuleEvaluation('pass', new DateTime('2026-09-10T10:00:59+00:00'))));
		$this->assertTrue($mapper->worthWriting($summary, new RuleEvaluation('pass', new DateTime('2026-09-10T10:01:00+00:00'))));
		$this->assertTrue($mapper->worthWriting($summary, new RuleEvaluation('pass', new DateTime('2026-09-10T10:00:30+00:00'), '')) === false);
	}//end testTheSameVerdictIsThrottled()

	/**
	 * A first evaluation inserts a new summary carrying the verdict and the error.
	 *
	 * @return void
	 */
	public function testAFirstEvaluationInsertsTheSummary(): void {
		$moment  = new DateTime('2026-09-10T10:00:00+00:00');
		$summary = $this->mapper()->record($this->ruleRun(), new RuleEvaluation('error', $moment, 'operand missing'));

		$this->assertSame(42, $summary->getId());
		$this->assertSame('rule-1', $summary->getRuleId());
		$this->assertSame('zaak', $summary->getSchemaSlug());
		$this->assertSame('error', $summary->getLastVerdict());
		$this->assertSame('operand missing', $summary->getLastError());
		$this->assertSame($moment, $summary->getLastRun());
		$this->assertSame($moment, $summary->getLastErrorAt());
		$this->assertContains('#2 insert(openregister_rule_summaries)', $this->queryLog);
	}//end testAFirstEvaluationInsertsTheSummary()

	/**
	 * A throttled evaluation of a stored summary writes nothing.
	 *
	 * @return void
	 */
	public function testAThrottledEvaluationWritesNothing(): void {
		$this->queuedRows[] = [['id' => 7, 'rule_id' => 'rule-1', 'schema_slug' => 'zaak', 'last_verdict' => 'pass', 'last_run' => '2026-09-10 10:00:00']];

		$summary = $this->mapper()->record($this->ruleRun(), new RuleEvaluation('pass', new DateTime('2026-09-10T10:00:20+00:00')));

		$this->assertSame(7, $summary->getId());
		$this->assertNull($summary->getLastError());
		$this->assertSame(1, $this->builderCount, 'only the read happened');
		$this->assertNotContains('#1 executeStatement()', $this->queryLog);
	}//end testAThrottledEvaluationWritesNothing()

	/**
	 * A changed verdict on a stored summary updates it and keeps the earlier error out.
	 *
	 * @return void
	 */
	public function testAChangedVerdictUpdatesTheStoredSummary(): void {
		$this->queuedRows[] = [['id' => 7, 'rule_id' => 'rule-1', 'schema_slug' => 'old', 'last_verdict' => 'pass', 'last_run' => '2026-09-10 10:00:00']];
		$moment = new DateTime('2026-09-10T10:00:20+00:00');

		$summary = $this->mapper()->record($this->ruleRun(), new RuleEvaluation('fail', $moment));

		$this->assertSame(7, $summary->getId());
		$this->assertSame('fail', $summary->getLastVerdict());
		$this->assertSame('zaak', $summary->getSchemaSlug());
		$this->assertNull($summary->getLastErrorAt());
		$this->assertContains('#2 update(openregister_rule_summaries, NULL)', $this->queryLog);
		$this->assertContains('#2 set(last_verdict, :p4)', $this->queryLog);
		$this->assertContains('#2 where(eq(id, :p5, NULL))', $this->queryLog);
	}//end testAChangedVerdictUpdatesTheStoredSummary()

	/**
	 * Summaries of a schema are keyed by rule id; a rule without one reads null.
	 *
	 * @return void
	 */
	public function testReadsBySchemaAndRule(): void {
		$this->queuedRows[] = [
			['id' => 1, 'rule_id' => 'rule-a', 'schema_slug' => 'zaak'],
			['id' => 2, 'rule_id' => 'rule-b', 'schema_slug' => 'zaak'],
		];
		$mapper = $this->mapper();

		$keyed = $mapper->findBySchema('zaak');
		$this->assertSame(['rule-a', 'rule-b'], array_keys($keyed));
		$this->assertSame(2, $keyed['rule-b']->getId());

		$this->assertNull($mapper->findByRule('rule-z'));
		$this->assertContains('#2 setMaxResults(1)', $this->queryLog);
	}//end testReadsBySchemaAndRule()

	/**
	 * The error list is newest first and asks for at least one row.
	 *
	 * @return void
	 */
	public function testErrorListIsNewestFirstAndAtLeastOne(): void {
		$this->mapper()->findHoldingAnError(0);

		$this->assertContains('#1 expr.isNotNull(last_error)', $this->queryLog);
		$this->assertContains('#1 orderBy(last_error_at, DESC)', $this->queryLog);
		$this->assertContains('#1 addOrderBy(id, DESC)', $this->queryLog);
		$this->assertContains('#1 setMaxResults(1)', $this->queryLog);
	}//end testErrorListIsNewestFirstAndAtLeastOne()
}//end class
