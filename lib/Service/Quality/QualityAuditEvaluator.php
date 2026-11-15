<?php

/**
 * QualityAuditEvaluator — runs a quality audit over a population and judges it.
 *
 * A quality audit declares a population (a register and schema, optionally
 * narrowed by filters), a rule set and a tolerance. A run evaluates the rules
 * over every object of the population with the same scorer that scores an
 * object on save (design D-3), so one rule cannot mean two things, and keeps
 * the tolerance it was judged against (design D-4).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
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

namespace OCA\OpenRegister\Service\Quality;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Checks an audit definition, evaluates a run and exports its failures.
 *
 * A record fails a rule when the scorer, given that one rule, scores it below
 * 1. The population score is the mean of the records' scores over the whole
 * rule set; a run passes when that score reaches the tolerance.
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */
class QualityAuditEvaluator {

	/**
	 * The rule types the scorer evaluates.
	 *
	 * @var array<int, string>
	 */
	public const RULE_TYPES = ['required', 'format', 'freshness'];

	/**
	 * A run that reached its tolerance.
	 *
	 * @var string
	 */
	public const VERDICT_PASS = 'pass';

	/**
	 * A run that fell below its tolerance.
	 *
	 * @var string
	 */
	public const VERDICT_FAIL = 'fail';

	/**
	 * Constructor.
	 *
	 * @param QualityScorerInterface $scorer The per-object scorer that also scores on save.
	 */
	public function __construct(
		private readonly QualityScorerInterface $scorer,
	) {
	}//end __construct()

	/**
	 * What is wrong with an audit definition, one sentence per fault.
	 *
	 * An audit needs a name, a population naming a register and a schema
	 * (its optional `filters` an object), at least one rule of a type the
	 * scorer knows with the field it reads, and a tolerance from 0 to 1.
	 *
	 * @param array<string, mixed> $audit The audit definition.
	 *
	 * @return array<int, string> The faults; empty when the audit can be saved.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-declares-a-population-a-rule-set-and-a-tolerance-req-rcn-003
	 */
	public function definitionFaults(array $audit): array {
		$faults = [];
		if (trim((string)($audit['name'] ?? '')) === '') {
			$faults[] = 'name: an audit needs a name.';
		}

		$faults = array_merge($faults, $this->populationFaults(population: ($audit['population'] ?? null)));
		$faults = array_merge($faults, $this->ruleFaults(rules: ($audit['rules'] ?? null)));

		$tolerance = ($audit['tolerance'] ?? null);
		if ((is_int($tolerance) === false && is_float($tolerance) === false) || $tolerance < 0 || $tolerance > 1) {
			$faults[] = 'tolerance: a number from 0 to 1.';
		}

		return $faults;
	}//end definitionFaults()

	/**
	 * Evaluate an audit over its population.
	 *
	 * @param array<string, mixed>                  $audit      The audit definition; its faults are checked by the caller.
	 * @param iterable<ObjectEntity|array>          $population The objects the population query answered.
	 * @param DateTimeImmutable                     $now        The moment of the run.
	 * @param string                                $startedBy  The user id, or `schedule`, that started the run.
	 * @param callable|null                         $onFailure  Receives each failing record as `array{record, rules}`
	 *                                                          instead of the result collecting it, so a large
	 *                                                          population is never held in memory (ADR-009).
	 *
	 * @return array{startedAt: string, startedBy: string, evaluated: int, score: float, tolerance: float, verdict: string,
	 *     failed: int, failures: array<int, array{record: string, rules: array<int, string>}>}
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	public function evaluate(array $audit, iterable $population, DateTimeImmutable $now, string $startedBy, ?callable $onFailure = null): array {
		$rules = array_values((array)($audit['rules'] ?? []));
		$tolerance = (float)($audit['tolerance'] ?? 1.0);

		$evaluated = 0;
		$total = 0.0;
		$failed = 0;
		$failures = [];
		foreach ($population as $member) {
			$object = $this->dataOf(member: $member);
			$evaluated++;
			$total += $this->scorer->score(object: $object, rules: $rules, now: $now);

			$failedRules = $this->failedRules(object: $object, rules: $rules, now: $now);
			if ($failedRules === []) {
				continue;
			}

			$failed++;
			$failure = ['record' => $this->idOf(member: $member, object: $object), 'rules' => $failedRules];
			if ($onFailure !== null) {
				$onFailure($failure);
				continue;
			}

			$failures[] = $failure;
		}

		$score = 1.0;
		if ($evaluated > 0) {
			$score = round($total / $evaluated, 4);
		}

		return [
			'startedAt' => $now->format(DATE_ATOM),
			'startedBy' => $startedBy,
			'evaluated' => $evaluated,
			'score' => $score,
			'tolerance' => $tolerance,
			'verdict' => $this->verdict(score: $score, tolerance: $tolerance),
			'failed' => $failed,
			'failures' => $failures,
		];
	}//end evaluate()

	/**
	 * The verdict of a score against a tolerance.
	 *
	 * @param float $score     The population score.
	 * @param float $tolerance The tolerance the run is judged against.
	 *
	 * @return string `pass` or `fail`.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-declares-a-population-a-rule-set-and-a-tolerance-req-rcn-003
	 */
	public function verdict(float $score, float $tolerance): string {
		if ($score >= $tolerance) {
			return self::VERDICT_PASS;
		}

		return self::VERDICT_FAIL;
	}//end verdict()

	/**
	 * The failing records of a run as CSV: one row per record with the rules it failed.
	 *
	 * @param array{failures?: array<int, array{record: string, rules: array<int, string>}>} $run The run.
	 *
	 * @return string The CSV, with a header row.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	public function failuresCsv(array $run): string {
		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			return '';
		}

		fputcsv($handle, ['record', 'rules'], ',', '"', '');
		foreach (($run['failures'] ?? []) as $failure) {
			fputcsv($handle, [(string)$failure['record'], implode('; ', $failure['rules'])], ',', '"', '');
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end failuresCsv()

	/**
	 * The label a rule is reported under: its `name`, else its type and field.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	public static function ruleLabel(array $rule): string {
		$name = trim((string)($rule['name'] ?? ''));
		if ($name !== '') {
			return $name;
		}

		return (string)($rule['type'] ?? '') . ':' . (string)($rule['field'] ?? '');
	}//end ruleLabel()

	/**
	 * The faults of a population.
	 *
	 * @param mixed $population The declared population.
	 *
	 * @return array<int, string> The faults.
	 */
	private function populationFaults(mixed $population): array {
		if (is_array($population) === false) {
			return ['population: an audit needs a population naming a register and a schema.'];
		}

		$faults = [];
		foreach (['register', 'schema'] as $key) {
			$value = ($population[$key] ?? null);
			if ((is_string($value) === false || trim($value) === '') && is_int($value) === false) {
				$faults[] = sprintf('population.%s: the population names no %s.', $key, $key);
			}
		}

		$filters = ($population['filters'] ?? []);
		if (is_array($filters) === false || ($filters !== [] && array_is_list($filters) === true)) {
			$faults[] = 'population.filters: an object of field and value.';
		}

		return $faults;
	}//end populationFaults()

	/**
	 * The faults of a rule set.
	 *
	 * @param mixed $rules The declared rules.
	 *
	 * @return array<int, string> The faults.
	 */
	private function ruleFaults(mixed $rules): array {
		if (is_array($rules) === false || $rules === []) {
			return ['rules: an audit needs at least one rule.'];
		}

		$faults = [];
		foreach (array_values($rules) as $index => $rule) {
			if (is_array($rule) === false || in_array(($rule['type'] ?? null), self::RULE_TYPES, true) === false) {
				$faults[] = sprintf('rules[%d]: the type is one of %s.', $index, implode(', ', self::RULE_TYPES));
				continue;
			}

			if (trim((string)($rule['field'] ?? '')) === '') {
				$faults[] = sprintf('rules[%d]: the rule names no field.', $index);
			}
		}

		return $faults;
	}//end ruleFaults()

	/**
	 * The labels of the rules an object fails: those the scorer scores below 1 on their own.
	 *
	 * @param array<string, mixed>             $object The object's data.
	 * @param array<int, array<string, mixed>> $rules  The rules.
	 * @param DateTimeImmutable                $now    The moment of the run.
	 *
	 * @return array<int, string> The labels, in rule order.
	 */
	private function failedRules(array $object, array $rules, DateTimeImmutable $now): array {
		$failed = [];
		foreach ($rules as $rule) {
			if (is_array($rule) === false) {
				continue;
			}

			if ($this->scorer->score(object: $object, rules: [$rule], now: $now) < 1.0) {
				$failed[] = self::ruleLabel(rule: $rule);
			}
		}

		return $failed;
	}//end failedRules()

	/**
	 * The data of a population member.
	 *
	 * @param mixed $member An object entity or its data.
	 *
	 * @return array<string, mixed> The data.
	 */
	private function dataOf(mixed $member): array {
		if ($member instanceof ObjectEntity) {
			return (array)$member->getObject();
		}

		if (is_array($member) === true) {
			return $member;
		}

		return [];
	}//end dataOf()

	/**
	 * The identity a failing record is reported under.
	 *
	 * @param mixed                $member The member.
	 * @param array<string, mixed> $object Its data.
	 *
	 * @return string The uuid, else the data's id.
	 */
	private function idOf(mixed $member, array $object): string {
		if ($member instanceof ObjectEntity && (string)$member->getUuid() !== '') {
			return (string)$member->getUuid();
		}

		return (string)($object['id'] ?? $object['uuid'] ?? ($object['@self']['id'] ?? ''));
	}//end idOf()
}//end class
