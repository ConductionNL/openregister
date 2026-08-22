<?php

/**
 * Unit tests for ScheduledFilterParser.
 *
 * The parser is the single gate between what an author may write and what the
 * engine can run, so these tests are written as a specification of the accept
 * set — including the four dialects the fleet actually shipped, verbatim:
 *
 *   - decidesk's bare list (18 rules) — must parse;
 *   - shillinq's `all` + `notIn` + `before` (4 rules) — must parse;
 *   - openconnector's `op` key with `lt` (1 rule, 2 entries) — must reject,
 *     with a message naming `operator`;
 *   - the canonical operator object and scalar forms — must keep parsing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\ScheduledFilterParser;
use PHPUnit\Framework\TestCase;

final class ScheduledFilterParserTest extends TestCase {

	private ScheduledFilterParser $parser;

	protected function setUp(): void {
		$this->parser = new ScheduledFilterParser();

	}//end setUp()

	/**
	 * Assert a filter parses, and return its AST.
	 *
	 * @param array<string, mixed> $filter The filter under test.
	 *
	 * @return array<string, mixed> The parsed AST.
	 */
	private function assertParses(array $filter): array {
		$result = $this->parser->parse(filter: $filter, ruleKey: 'rule');

		self::assertSame([], $result['errors'], 'expected no parse errors');
		self::assertNotNull($result['ast']);

		return $result['ast'];

	}//end assertParses()

	/**
	 * Assert a filter is rejected with a given error code.
	 *
	 * @param array<string, mixed> $filter The filter under test.
	 * @param string               $code   The expected error code.
	 *
	 * @return array<string, mixed> The first error.
	 */
	private function assertRejected(array $filter, string $code): array {
		$result = $this->parser->parse(filter: $filter, ruleKey: 'rule');

		self::assertNull($result['ast'], 'expected no AST for a rejected filter');
		self::assertNotEmpty($result['errors']);
		self::assertSame($code, $result['errors'][0]['code']);

		return $result['errors'][0];

	}//end assertRejected()

	// ---------------------------------------------------------------- accepts

	public function testScalarEntryParsesAsStrictEquality(): void {
		$ast = $this->assertParses(['lifecycleState' => 'overdue']);

		self::assertSame('all', $ast['type']);
		self::assertSame('equals', $ast['clauses'][0]['operator']);
		self::assertSame('overdue', $ast['clauses'][0]['operand']);

	}//end testScalarEntryParsesAsStrictEquality()

	public function testDecideskBareListParsesAsMembership(): void {
		// 45-toezeggingen-ingekomen-stukken.json and 17 siblings.
		$ast = $this->assertParses(['lifecycle' => ['open', 'in-uitvoering']]);

		self::assertSame('in', $ast['clauses'][0]['operator']);
		self::assertSame(['open', 'in-uitvoering'], $ast['clauses'][0]['operand']);

	}//end testDecideskBareListParsesAsMembership()

	public function testShillinqCombinatorParses(): void {
		// bookkeeping-accounts-payable-core.json APTransaction.overdue.
		$ast = $this->assertParses(
			[
				'all' => [
					['field' => 'state', 'operator' => 'notIn', 'values' => ['paid', 'written-off', 'voided']],
					['field' => 'dueDate', 'operator' => 'before', 'value' => 'now'],
				],
			]
		);

		$combinator = $ast['clauses'][0];
		self::assertSame('all', $combinator['type']);
		self::assertCount(2, $combinator['clauses']);
		self::assertSame('notIn', $combinator['clauses'][0]['operator']);
		self::assertSame('before', $combinator['clauses'][1]['operator']);

	}//end testShillinqCombinatorParses()

	public function testCanonicalOperatorObjectStillParses(): void {
		$ast = $this->assertParses(['dueDate' => ['operator' => 'withinNext', 'value' => 'PT24H']]);

		self::assertSame('withinNext', $ast['clauses'][0]['operator']);

	}//end testCanonicalOperatorObjectStillParses()

	public function testInstantAcceptsNowAbsoluteDateAndSignedDuration(): void {
		foreach (['now', '2026-01-01', '2026-01-01T00:00:00Z', 'P7D', '-P7D'] as $instant) {
			$this->assertParses(['d' => ['operator' => 'before', 'value' => $instant]]);
		}

	}//end testInstantAcceptsNowAbsoluteDateAndSignedDuration()

	public function testCombinatorsMayNest(): void {
		$this->assertParses(
			[
				'all' => [
					['any' => [['field' => 'a', 'operator' => 'equals', 'value' => 1]]],
				],
			]
		);

	}//end testCombinatorsMayNest()

	// ---------------------------------------------------------------- rejects

	public function testOpenconnectorOpKeyIsRejectedNamingOperator(): void {
		// openconnector_register.json:1154 job.job-overdue.
		$error = $this->assertRejected(
			['isEnabled' => ['op' => 'equals', 'value' => true]],
			'notification-scheduled-bad-filter-operator-key'
		);

		self::assertStringContainsString('"operator"', $error['message']);
		self::assertStringContainsString('"op"', $error['message']);

	}//end testOpenconnectorOpKeyIsRejectedNamingOperator()

	public function testUnknownOperatorIsRejected(): void {
		$error = $this->assertRejected(
			['nextRun' => ['operator' => 'lt', 'value' => 'now']],
			'notification-scheduled-bad-filter-operator'
		);

		self::assertStringContainsString('lt', $error['message']);

	}//end testUnknownOperatorIsRejected()

	public function testMapWithoutOperatorIsRejectedRatherThanTreatedAsScalar(): void {
		// The exact hole this change closes: previously "accepted, then never
		// matched". A map that is neither scalar, list, nor operator object.
		$this->assertRejected(
			['something' => ['unexpected' => 'shape']],
			'notification-scheduled-bad-filter-shape'
		);

	}//end testMapWithoutOperatorIsRejectedRatherThanTreatedAsScalar()

	public function testMembershipRequiresNonEmptyValues(): void {
		$this->assertRejected(
			['s' => ['operator' => 'in', 'values' => []]],
			'notification-scheduled-bad-filter-values'
		);

		$this->assertRejected(
			['s' => ['operator' => 'in', 'value' => 'a']],
			'notification-scheduled-bad-filter-missing-value'
		);

	}//end testMembershipRequiresNonEmptyValues()

	public function testEmptyBareListIsRejected(): void {
		$this->assertRejected(['s' => []], 'notification-scheduled-empty-list');

	}//end testEmptyBareListIsRejected()

	public function testUnparseableDurationIsRejected(): void {
		$this->assertRejected(
			['d' => ['operator' => 'withinNext', 'value' => 'soon']],
			'notification-scheduled-bad-filter-duration'
		);

	}//end testUnparseableDurationIsRejected()

	public function testUnresolvableInstantIsRejected(): void {
		$this->assertRejected(
			['d' => ['operator' => 'before', 'value' => 'whenever']],
			'notification-scheduled-bad-filter-instant'
		);

	}//end testUnresolvableInstantIsRejected()

	public function testCombinatorMustBeAList(): void {
		// A map rather than a list of clauses — the combinator itself is wrong.
		$this->assertRejected(['all' => ['field' => 'a']], 'notification-scheduled-bad-combinator');

	}//end testCombinatorMustBeAList()

	public function testCombinatorClauseMustBeAnObject(): void {
		// A list, but holding a scalar where a clause belongs.
		$this->assertRejected(['all' => ['not-a-clause']], 'notification-scheduled-bad-clause');

	}//end testCombinatorClauseMustBeAnObject()

	public function testClauseRequiresAFieldName(): void {
		$this->assertRejected(
			['all' => [['operator' => 'equals', 'value' => 1]]],
			'notification-scheduled-bad-clause-field'
		);

	}//end testClauseRequiresAFieldName()

	public function testNestingIsBounded(): void {
		// Six levels — one past MAX_DEPTH.
		$filter = ['field' => 'a', 'operator' => 'equals', 'value' => 1];
		for ($i = 0; $i < 6; $i++) {
			$filter = ['all' => [$filter]];
		}

		$result = $this->parser->parse(filter: $filter, ruleKey: 'rule');

		self::assertNull($result['ast']);
		self::assertSame('notification-scheduled-filter-too-deep', $result['errors'][0]['code']);

	}//end testNestingIsBounded()

	public function testEmptyFilterParsesToAnEmptyConjunction(): void {
		$ast = $this->assertParses([]);

		self::assertSame('all', $ast['type']);
		self::assertSame([], $ast['clauses']);

	}//end testEmptyFilterParsesToAnEmptyConjunction()

}//end class
