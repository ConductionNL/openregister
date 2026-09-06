<?php

/**
 * The subject-scoped run reads: what predicates reach the datastore.
 *
 * A case detail page asks "what is running on THIS object, and what already
 * ran". Both answers must be narrowed INSIDE the caller's organisation, in the
 * database, and never widened by the subject: a subject uuid is guessable, so
 * a filter that replaced the organisation predicate would turn the case anchor
 * into a cross-tenant read primitive.
 *
 * These tests record the predicates, ordering and bounds each read builds and
 * assert on them directly, because that is where the property lives. The
 * two-organisation scenario in the spec ("a matching subject in organisation B
 * stays invisible to a caller in organisation A") is, at this layer, exactly
 * the assertion that the `organisation = ?` predicate is present next to the
 * `subject_uuid = ?` predicate on every one of the four reads.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions; the test name is the statement.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class FlowRunMapperSubjectScopeTest extends TestCase {

	/**
	 * Every predicate the query received, in order, rendered as `column OP value`.
	 *
	 * @var array<int, string>
	 */
	private array $predicates = [];

	/**
	 * The `orderBy` calls, as `[column, direction]` pairs.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $orderBy = [];

	/**
	 * The `setMaxResults` argument, or null when never bounded.
	 *
	 * @var integer|null
	 */
	private ?int $limit = null;

	/**
	 * How many query builders the mapper asked for.
	 *
	 * @var integer
	 */
	private int $queries = 0;

	/**
	 * A mapper over a connection whose query builder records what is asked of it.
	 *
	 * The expression builder renders `eq()`/`in()` as readable strings and
	 * `createNamedParameter()` returns the VALUE (json-encoded) instead of a
	 * placeholder, so a recorded predicate reads `organisation = "org-a"` and
	 * can be asserted on directly. The result set is empty: what is under test
	 * is the question, not the rows.
	 *
	 * @param integer $total What a COUNT(*) query answers.
	 *
	 * @return FlowRunMapper The mapper.
	 */
	private function recordingMapper(int $total = 0): FlowRunMapper {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			static fn (string $column, string $value): string => $column . ' = ' . $value
		);
		$expr->method('in')->willReturnCallback(
			static fn (string $column, string $value): string => $column . ' IN ' . $value
		);

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$result->method('fetchOne')->willReturn($total);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value): string => (string)json_encode($value)
		);
		$qb->method('createFunction')->willReturnArgument(0);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnCallback(
			function (string $predicate) use ($qb): IQueryBuilder {
				$this->predicates[] = $predicate;
				return $qb;
			}
		);
		$qb->method('andWhere')->willReturnCallback(
			function (string $predicate) use ($qb): IQueryBuilder {
				$this->predicates[] = $predicate;
				return $qb;
			}
		);
		$qb->method('orderBy')->willReturnCallback(
			function (string $column, ?string $direction = null) use ($qb): IQueryBuilder {
				$this->orderBy[] = [$column, (string)$direction];
				return $qb;
			}
		);
		$qb->method('setMaxResults')->willReturnCallback(
			function (?int $limit) use ($qb): IQueryBuilder {
				$this->limit = $limit;
				return $qb;
			}
		);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use ($qb): IQueryBuilder {
				$this->queries++;
				return $qb;
			}
		);

		return new FlowRunMapper($db);
	}//end recordingMapper()

	/**
	 * The status set as the recording builder renders it.
	 *
	 * @param array<int, string> $statuses The status set.
	 *
	 * @return string The rendered IN predicate.
	 */
	private static function statusIn(array $statuses): string {
		return 'status IN ' . json_encode($statuses);
	}//end statusIn()

	public function testFindActiveWithASubjectKeepsTheOrganisationPredicateAndAddsTheSubject(): void {
		$mapper = $this->recordingMapper();

		$mapper->findActive(organisation: 'org-a', limit: 25, subject: 'case-x');

		// The organisation predicate is unconditional and comes FIRST; the
		// subject narrows after it. This is the two-organisation scenario: a
		// caller in org A asking for a subject that lives in org B hits
		// `organisation = "org-a"` before `subject_uuid` is ever consulted.
		$this->assertSame(
			[self::statusIn(FlowRun::ACTIVE), 'organisation = "org-a"', 'subject_uuid = "case-x"'],
			$this->predicates
		);
	}//end testFindActiveWithASubjectKeepsTheOrganisationPredicateAndAddsTheSubject()

	public function testFindActiveWithoutASubjectIsTodaysRead(): void {
		$mapper = $this->recordingMapper();

		$mapper->findActive(organisation: 'org-a', limit: 25);

		$this->assertSame([self::statusIn(FlowRun::ACTIVE), 'organisation = "org-a"'], $this->predicates);
		$this->assertSame([['id', 'DESC']], $this->orderBy);
		$this->assertSame(25, $this->limit);
	}//end testFindActiveWithoutASubjectIsTodaysRead()

	public function testABlankSubjectAddsNoPredicate(): void {
		$mapper = $this->recordingMapper();

		$mapper->findActive(organisation: 'org-a', limit: 25, subject: '');

		$this->assertNotContains('subject_uuid = ""', $this->predicates);
		$this->assertCount(2, $this->predicates);
	}//end testABlankSubjectAddsNoPredicate()

	public function testCountActiveSharesTheRowPredicatesSoTheTotalIsHonest(): void {
		$mapper = $this->recordingMapper(total: 2);

		$total = $mapper->countActive(organisation: 'org-a', subject: 'case-x');

		$this->assertSame(2, $total);
		$this->assertSame(
			[self::statusIn(FlowRun::ACTIVE), 'organisation = "org-a"', 'subject_uuid = "case-x"'],
			$this->predicates
		);
	}//end testCountActiveSharesTheRowPredicatesSoTheTotalIsHonest()

	public function testTheCompletedReadAsksForTheTerminalSetOnThisOrganisationAndSubjectNewestFirst(): void {
		$mapper = $this->recordingMapper();

		$mapper->findCompletedForSubject(organisation: 'org-a', subject: 'case-x', limit: 7);

		$this->assertSame(
			[self::statusIn(FlowRun::TERMINAL), 'organisation = "org-a"', 'subject_uuid = "case-x"'],
			$this->predicates
		);
		$this->assertSame([['id', 'DESC']], $this->orderBy);
		$this->assertSame(7, $this->limit);
	}//end testTheCompletedReadAsksForTheTerminalSetOnThisOrganisationAndSubjectNewestFirst()

	public function testTheTerminalSetTheCompletedReadUsesIncludesFailed(): void {
		// "A failed run is history too": the read is driven by the entity's
		// terminal set, and that set carries `failed`. If someone ever trims it
		// to `completed` alone, a failed hersteltermijn vanishes from its case.
		$this->assertContains(FlowRun::STATUS_FAILED, FlowRun::TERMINAL);
		$this->assertContains(FlowRun::STATUS_COMPLETED, FlowRun::TERMINAL);
		$this->assertContains(FlowRun::STATUS_STOPPED, FlowRun::TERMINAL);
		$this->assertContains(FlowRun::STATUS_DEAD_LETTER, FlowRun::TERMINAL);

		$mapper = $this->recordingMapper();
		$mapper->findCompletedForSubject(organisation: 'org-a', subject: 'case-x');

		$this->assertStringContainsString('"failed"', $this->predicates[0]);
	}//end testTheTerminalSetTheCompletedReadUsesIncludesFailed()

	public function testTheCompletedCountSharesThePredicates(): void {
		$mapper = $this->recordingMapper(total: 14);

		$total = $mapper->countCompletedForSubject(organisation: 'org-a', subject: 'case-x');

		$this->assertSame(14, $total);
		$this->assertSame(
			[self::statusIn(FlowRun::TERMINAL), 'organisation = "org-a"', 'subject_uuid = "case-x"'],
			$this->predicates
		);
	}//end testTheCompletedCountSharesThePredicates()

	public function testTheCompletedReadFailsClosedWithoutASubjectOrOrganisation(): void {
		$mapper = $this->recordingMapper(total: 99);

		// Dropping a required predicate would answer a WIDER question than was
		// asked. Nothing is queried; the answer is empty and zero.
		$this->assertSame([], $mapper->findCompletedForSubject(organisation: 'org-a', subject: ''));
		$this->assertSame([], $mapper->findCompletedForSubject(organisation: '', subject: 'case-x'));
		$this->assertSame(0, $mapper->countCompletedForSubject(organisation: 'org-a', subject: ''));
		$this->assertSame(0, $mapper->countCompletedForSubject(organisation: '', subject: 'case-x'));
		$this->assertSame(0, $this->queries);
	}//end testTheCompletedReadFailsClosedWithoutASubjectOrOrganisation()
}//end class
