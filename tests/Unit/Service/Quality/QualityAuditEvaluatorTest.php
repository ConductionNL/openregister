<?php

/**
 * Tests for QualityAuditEvaluator (runs-recorded-and-causes-named, section 2).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Quality;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Quality\QualityAuditEvaluator;
use OCA\OpenRegister\Service\Quality\QualityScorer;
use OCA\OpenRegister\Service\Quality\QualityScoreRun;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The address register scenario, against the real scorer.
 */
#[CoversClass(QualityAuditEvaluator::class)]
#[UsesClass(QualityScorer::class)]
#[UsesClass(QualityScoreRun::class)]
class QualityAuditEvaluatorTest extends TestCase {

	/**
	 * The audit over the address register: postcode and house number required, at 0.95.
	 *
	 * @var array<string, mixed>
	 */
	private const AUDIT = [
		'name' => 'Address register completeness',
		'population' => ['register' => 'bag', 'schema' => 'address'],
		'rules' => [
			['type' => 'required', 'field' => 'postcode'],
			['type' => 'required', 'field' => 'houseNumber', 'name' => 'house number present'],
		],
		'tolerance' => 0.95,
	];

	/**
	 * The evaluator over the real scorer.
	 *
	 * @var QualityAuditEvaluator
	 */
	private QualityAuditEvaluator $evaluator;

	/**
	 * Build the evaluator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->evaluator = new QualityAuditEvaluator(scorer: new QualityScorer());
	}//end setUp()

	/**
	 * Twenty addresses; the given ones lack a postcode or a house number.
	 *
	 * @param array<int, int> $noPostcode    The numbers without a postcode.
	 * @param array<int, int> $noHouseNumber The numbers without a house number.
	 *
	 * @return array<int, ObjectEntity> The population.
	 */
	private static function addresses(array $noPostcode, array $noHouseNumber): array {
		$population = [];
		for ($i = 1; $i <= 20; $i++) {
			$entity = new ObjectEntity();
			$entity->setUuid('address-' . $i);
			$entity->setObject(
				[
					'postcode' => in_array($i, $noPostcode, true) ? '' : '3511AB',
					'houseNumber' => in_array($i, $noHouseNumber, true) ? null : $i,
				]
			);
			$population[] = $entity;
		}

		return $population;
	}//end addresses()

	/**
	 * A run reports one score, the tolerance it was judged at and a verdict.
	 *
	 * @return void
	 */
	public function testARunReportsOneScoreAndAVerdict(): void {
		$run = $this->evaluator->evaluate(
			audit: self::AUDIT,
			population: self::addresses(noPostcode: [3], noHouseNumber: [3, 7]),
			now: new DateTimeImmutable('2026-11-01T09:00:00+00:00'),
			startedBy: 'steward'
		);

		$this->assertSame('2026-11-01T09:00:00+00:00', $run['startedAt']);
		$this->assertSame('steward', $run['startedBy']);
		$this->assertSame(20, $run['evaluated']);
		// Address 3 scores 0, address 7 scores 0.5, eighteen score 1: 18.5 / 20.
		$this->assertSame(0.925, $run['score']);
		$this->assertSame(0.95, $run['tolerance']);
		$this->assertSame('fail', $run['verdict']);
	}//end testARunReportsOneScoreAndAVerdict()

	/**
	 * The failing records name the rules they failed, in rule order.
	 *
	 * @return void
	 */
	public function testTheFailuresNameTheirRules(): void {
		$run = $this->evaluator->evaluate(
			audit: self::AUDIT,
			population: self::addresses(noPostcode: [3], noHouseNumber: [3, 7]),
			now: new DateTimeImmutable(),
			startedBy: 'schedule'
		);

		$this->assertSame(
			[
				['record' => 'address-3', 'rules' => ['required:postcode', 'house number present']],
				['record' => 'address-7', 'rules' => ['house number present']],
			],
			$run['failures']
		);
	}//end testTheFailuresNameTheirRules()

	/**
	 * A population at or above the tolerance passes.
	 *
	 * @return void
	 */
	public function testAPopulationAtTheTolerancePasses(): void {
		$run = $this->evaluator->evaluate(
			audit: array_merge(self::AUDIT, ['tolerance' => 0.925]),
			population: self::addresses(noPostcode: [3], noHouseNumber: [3, 7]),
			now: new DateTimeImmutable(),
			startedBy: 'steward'
		);

		$this->assertSame('pass', $run['verdict']);
	}//end testAPopulationAtTheTolerancePasses()

	/**
	 * A run keeps the tolerance it was judged at; raising the audit's later does not re-judge it.
	 *
	 * @return void
	 */
	public function testAPastRunKeepsItsVerdict(): void {
		$audit = array_merge(self::AUDIT, ['tolerance' => 0.90]);
		$past = $this->evaluator->evaluate(audit: $audit, population: self::addresses(noPostcode: [3], noHouseNumber: [3, 7]), now: new DateTimeImmutable(), startedBy: 'steward');

		$audit['tolerance'] = 0.98;

		$this->assertSame(0.90, $past['tolerance']);
		$this->assertSame('pass', $past['verdict']);
		$this->assertSame('pass', $this->evaluator->verdict(score: $past['score'], tolerance: $past['tolerance']));
		$this->assertSame('fail', $this->evaluator->verdict(score: $past['score'], tolerance: $audit['tolerance']));
	}//end testAPastRunKeepsItsVerdict()

	/**
	 * Plain arrays are evaluated too, reported by their id.
	 *
	 * @return void
	 */
	public function testArrayMembersAreReportedByTheirId(): void {
		$run = $this->evaluator->evaluate(
			audit: self::AUDIT,
			population: [['id' => 'a', 'postcode' => '1234AB', 'houseNumber' => 1], ['uuid' => 'b', 'postcode' => '']],
			now: new DateTimeImmutable(),
			startedBy: 'steward'
		);

		$this->assertSame(2, $run['evaluated']);
		$this->assertSame([['record' => 'b', 'rules' => ['required:postcode', 'house number present']]], $run['failures']);
		$this->assertSame(0.5, $run['score']);
	}//end testArrayMembersAreReportedByTheirId()

	/**
	 * An empty population evaluates nothing and fails nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyPopulation(): void {
		$run = $this->evaluator->evaluate(audit: self::AUDIT, population: [], now: new DateTimeImmutable(), startedBy: 'schedule');

		$this->assertSame(0, $run['evaluated']);
		$this->assertSame(1.0, $run['score']);
		$this->assertSame('pass', $run['verdict']);
		$this->assertSame([], $run['failures']);
	}//end testAnEmptyPopulation()

	/**
	 * The failures export as CSV, one row per record with the rules it failed.
	 *
	 * @return void
	 */
	public function testTheFailuresExportAsCsv(): void {
		$csv = $this->evaluator->failuresCsv(
			run: ['failures' => [['record' => 'address-3', 'rules' => ['required:postcode', 'house number present']], ['record' => 'a,"b"', 'rules' => ['x']]]]
		);

		$this->assertSame("record,rules\naddress-3,\"required:postcode; house number present\"\n\"a,\"\"b\"\"\",x\n", $csv);
		$this->assertSame("record,rules\n", $this->evaluator->failuresCsv(run: []));
	}//end testTheFailuresExportAsCsv()

	/**
	 * A well-formed audit has no faults.
	 *
	 * @return void
	 */
	public function testAWellFormedAuditHasNoFaults(): void {
		$this->assertSame([], $this->evaluator->definitionFaults(audit: self::AUDIT));
		$this->assertSame([], $this->evaluator->definitionFaults(audit: array_merge(self::AUDIT, ['population' => ['register' => 1, 'schema' => 2, 'filters' => ['status' => 'active']], 'tolerance' => 1])));
	}//end testAWellFormedAuditHasNoFaults()

	/**
	 * Malformed audits, each with the fault it is refused for.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function malformed(): array {
		return [
			'no rules' => [['rules' => []], 'rules: an audit needs at least one rule.'],
			'rules missing' => [['rules' => null], 'rules: an audit needs at least one rule.'],
			'unknown rule type' => [['rules' => [['type' => 'regex', 'field' => 'x']]], 'rules[0]: the type is one of required, format, freshness.'],
			'rule without field' => [['rules' => [['type' => 'required']]], 'rules[0]: the rule names no field.'],
			'no population' => [['population' => null], 'population: an audit needs a population naming a register and a schema.'],
			'no schema' => [['population' => ['register' => 'bag']], 'population.schema: the population names no schema.'],
			'filters as a list' => [['population' => ['register' => 'bag', 'schema' => 'address', 'filters' => ['active']]], 'population.filters: an object of field and value.'],
			'tolerance above 1' => [['tolerance' => 1.5], 'tolerance: a number from 0 to 1.'],
			'tolerance as text' => [['tolerance' => '0.9'], 'tolerance: a number from 0 to 1.'],
			'no name' => [['name' => ' '], 'name: an audit needs a name.'],
		];
	}//end malformed()

	/**
	 * A malformed audit is refused naming the fault.
	 *
	 * @param array<string, mixed> $change The fields that differ from the address audit.
	 * @param string               $fault  The fault named.
	 *
	 * @return void
	 */
	#[DataProvider('malformed')]
	public function testAMalformedAuditIsRefusedNamingTheFault(array $change, string $fault): void {
		$this->assertSame([$fault], $this->evaluator->definitionFaults(audit: array_merge(self::AUDIT, $change)));
	}//end testAMalformedAuditIsRefusedNamingTheFault()

	/**
	 * A failure sink receives each failing record, so a large population is not held in memory.
	 *
	 * @return void
	 */
	public function testAFailureSinkReceivesEachFailureInsteadOfTheResult(): void {
		$seen = [];
		$run = $this->evaluator->evaluate(
			audit: self::AUDIT,
			population: self::addresses(noPostcode: [3], noHouseNumber: [3, 7]),
			now: new DateTimeImmutable(),
			startedBy: 'steward',
			onFailure: static function (array $failure) use (&$seen): void {
				$seen[] = $failure;
			}
		);

		$this->assertSame(
			[
				['record' => 'address-3', 'rules' => ['required:postcode', 'house number present']],
				['record' => 'address-7', 'rules' => ['house number present']],
			],
			$seen
		);
		$this->assertSame([], $run['failures']);
		$this->assertSame(2, $run['failed']);
		$this->assertSame(20, $run['evaluated']);
	}//end testAFailureSinkReceivesEachFailureInsteadOfTheResult()

	/**
	 * Without a sink the failures are on the result and counted.
	 *
	 * @return void
	 */
	public function testWithoutASinkTheFailuresAreCounted(): void {
		$run = $this->evaluator->evaluate(
			audit: self::AUDIT,
			population: self::addresses(noPostcode: [3], noHouseNumber: [3, 7]),
			now: new DateTimeImmutable(),
			startedBy: 'steward'
		);

		$this->assertSame(2, $run['failed']);
		$this->assertCount(2, $run['failures']);
	}//end testWithoutASinkTheFailuresAreCounted()

	/**
	 * A rule is reported by its name, else by type and field.
	 *
	 * @return void
	 */
	public function testRuleLabels(): void {
		$this->assertSame('postcode filled', QualityAuditEvaluator::ruleLabel(rule: ['name' => 'postcode filled', 'type' => 'required', 'field' => 'postcode']));
		$this->assertSame('format:email', QualityAuditEvaluator::ruleLabel(rule: ['type' => 'format', 'field' => 'email']));
	}//end testRuleLabels()
}//end class
