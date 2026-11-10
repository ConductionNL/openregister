<?php

/**
 * Golden record of CalculationEvaluator: every operator over its malformed,
 * null, non-numeric and normal operands, plus the expression walkers.
 *
 * The recording lives in fixtures/calculation-evaluator-golden.json; set
 * CALC_EVAL_GOLDEN_RECORD=1 to write it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
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
 * @spec exclude Pins the evaluator's answers per operator.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Calculation;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\SequenceContext;
use OCA\OpenRegister\Service\SequenceScope;
use OCA\OpenRegister\Service\SequenceService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * CalculationEvaluator answers as recorded.
 *
 * @covers \OCA\OpenRegister\Service\Calculation\CalculationEvaluator
 * @uses   \OCA\OpenRegister\Service\Calculation\RateSchedule
 * @uses   \OCA\OpenRegister\Service\Calculation\PayloadPathWalking
 * @uses   \OCA\OpenRegister\Service\Calculation\PropNameReading
 * @uses   \OCA\OpenRegister\Service\Calculation\SequenceContext
 * @uses   \OCA\OpenRegister\Service\Calculation\EvaluationException
 * @uses   \OCA\OpenRegister\Search\Service\Search\PlaceholderResolver
 */
class CalculationEvaluatorGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/calculation-evaluator-golden.json';

	/**
	 * The object every expression reads.
	 *
	 * @var array<string, mixed>
	 */
	private const OBJECT = [
		'name' => 'Ann',
		'n' => 7,
		'text' => 'abc',
		'zero' => 0,
		'nothing' => null,
		'start' => '2024-01-31',
		'stamp' => '2024-03-10T12:30:00+00:00',
		'bad' => 'not-a-date',
		'nested' => ['a' => ['b' => 5], 'empty' => null],
		'@self' => ['created' => '2023-05-01'],
		'bands' => [['upTo' => 10, 'rate' => 2], ['upTo' => null, 'rate' => 1]],
	];

	/**
	 * Expressions by label.
	 *
	 * @return array<string, mixed>
	 */
	private function expressions(): array {
		$p = static fn (string $name): array => ['prop' => $name];
		$out = [
			'scalar' => 5, 'scalar placeholder' => '$userId', 'null' => null, 'two keys' => ['a' => 1, 'b' => 2], 'empty' => [],
			'list node' => [1], 'unknown' => ['nope' => []], 'source' => ['source' => ['id' => 'x']],
			'lit' => ['lit' => '$userId'], 'lit array' => ['lit' => [1, 2]],
			'prop' => $p('name'), 'prop list' => ['prop' => ['n']], 'prop missing' => $p('gone'), 'prop null' => $p('nothing'),
			'prop dotted' => $p('nested.a.b'), 'prop dotted null' => $p('nested.empty'), 'prop dotted missing' => $p('nested.x.y'),
			'prop through scalar' => $p('name.x'), 'prop self' => $p('@self.created'), 'prop empty' => ['prop' => ''],
			'prop int' => ['prop' => 3], 'prop empty list' => ['prop' => []],
			'concat' => ['concat' => [$p('name'), '-', 1, null]], 'concat bare' => ['concat' => 'x'],
			'if true' => ['if' => [true, 'y', 'n']], 'if false' => ['if' => [0, 'y', 'n']], 'if no else' => ['if' => ['', 'y']],
			'if short' => ['if' => [true]], 'if bare' => ['if' => 'x'],
			'not' => ['not' => [0]], 'not true' => ['not' => ['1']], 'not missing' => ['not' => []],
			'and all' => ['and' => [1, 'x']], 'and stops' => ['and' => [0, ['nope' => []]]], 'and bare' => ['and' => 1], 'and empty' => ['and' => []],
			'or first' => ['or' => [1, ['nope' => []]]], 'or none' => ['or' => [0, '0', null]], 'or bare' => ['or' => 1], 'or empty' => ['or' => []],
			'add' => ['+' => [1, '2', 3.5]], 'add empty' => ['+' => []], 'add bare' => ['+' => 1], 'add text' => ['+' => [1, 'x', ['nope' => []]]],
			'multiply' => ['*' => [2, '3']], 'multiply empty' => ['*' => []], 'multiply null' => ['*' => [2, null]], 'multiply bare' => ['*' => 2],
			'add overflow' => ['+' => [PHP_INT_MAX, 1]],
			'minus one' => ['-' => [4]], 'minus text' => ['-' => ['4.5']], 'minus many' => ['-' => [10, 1.5, '2']], 'minus empty' => ['-' => []],
			'minus bare' => ['-' => 2], 'minus first bad' => ['-' => ['x', 1]], 'minus later bad' => ['-' => [1, 'x']],
			'minus float order' => ['-' => [0.3, 0.1, 0.1]],
			'divide' => ['/' => [7, 2]], 'divide by zero' => ['/' => [7, 0]], 'divide zero text' => ['/' => [7, '0.0']], 'divide text' => ['/' => ['x', 1]],
			'divide short' => ['/' => [7]], 'divide bare' => ['/' => 7], 'divide denominator text' => ['/' => [1, 'x']],
			'mod' => ['%' => [7, 3]], 'mod float' => ['%' => [7.5, 2]], 'mod zero' => ['%' => [7, 0]], 'mod text' => ['%' => [7, 'x']], 'mod short' => ['%' => [7]],
			'mod numerator text' => ['%' => ['x', 7]], 'mod bare' => ['%' => 'x'],
			'now type' => ['formatDate' => [['now' => []], '\f\i\x\e\d']],
			'diffDays' => ['diffDays' => ['2024-03-01', '2024-02-01']], 'diffDays negative' => ['diffDays' => ['2024-02-01', '2024-03-01T12:00:00Z']],
			'diffDays bad' => ['diffDays' => ['x', '2024-02-01']], 'diffDays bad second' => ['diffDays' => ['2024-02-01', null]], 'diffDays short' => ['diffDays' => ['2024-02-01']],
			'diffDays bare' => ['diffDays' => 'x'], 'diffDays mutable' => ['diffDays' => [['lit' => new DateTime('2024-01-03')], ['lit' => new DateTimeImmutable('2024-01-01')]]],
			'formatDate' => ['formatDate' => [$p('start'), 'd/m/Y']], 'formatDate bad' => ['formatDate' => [$p('bad'), 'Y']], 'formatDate short' => ['formatDate' => ['2024-01-01']],
			'formatDate empty' => ['formatDate' => ['', 'Y']], 'formatDate int' => ['formatDate' => [5, 'Y']], 'formatDate bare' => ['formatDate' => 'x'],
			'dateDiff bare' => ['dateDiff' => 'x'], 'dateDiff no from' => ['dateDiff' => ['to' => 'x', 'unit' => 'days']], 'dateDiff no to' => ['dateDiff' => ['from' => 'x', 'unit' => 'days']],
			'dateDiff no unit' => ['dateDiff' => ['from' => 'x', 'to' => 'x']], 'dateDiff bad unit' => ['dateDiff' => ['from' => 'x', 'to' => 'x', 'unit' => 'ages']],
			'dateDiff bad from' => ['dateDiff' => ['from' => 'x', 'to' => '2024-01-01', 'unit' => 'days']], 'dateDiff bad to' => ['dateDiff' => ['from' => '2024-01-01', 'to' => 'x', 'unit' => 'days']],
			'dateDiff now' => ['dateDiff' => ['from' => 'now', 'to' => 'now', 'unit' => 'years']], 'dateDiff null key' => ['dateDiff' => ['from' => null, 'to' => null, 'unit' => 'days']],
			'dateAdd bare' => ['dateAdd' => 'x'], 'dateAdd no date' => ['dateAdd' => ['amount' => 1, 'unit' => 'days']], 'dateAdd no shape' => ['dateAdd' => ['date' => '2024-01-01']],
			'dateAdd amount only' => ['dateAdd' => ['date' => '2024-01-01', 'amount' => 1]], 'dateAdd unit only' => ['dateAdd' => ['date' => '2024-01-01', 'unit' => 'days']],
			'dateAdd bad date' => ['dateAdd' => ['date' => 'not-a-date', 'amount' => 1, 'unit' => 'days']], 'dateAdd null date' => ['dateAdd' => ['date' => $p('nothing'), 'duration' => 'P1D']],
			'dateAdd duration' => ['dateAdd' => ['date' => '2024-01-31', 'duration' => 'P1M']], 'dateAdd bad duration' => ['dateAdd' => ['date' => '2024-01-31', 'duration' => 'nope']],
			'dateAdd empty duration' => ['dateAdd' => ['date' => '2024-01-31', 'duration' => '']], 'dateAdd int duration' => ['dateAdd' => ['date' => '2024-01-31', 'duration' => 5]],
			'dateAdd both shapes' => ['dateAdd' => ['date' => '2024-01-31', 'duration' => 'P1D', 'amount' => 5, 'unit' => 'years']],
			'dateAdd time' => ['dateAdd' => ['date' => $p('stamp'), 'amount' => '2', 'unit' => 'days']], 'dateAdd object' => ['dateAdd' => ['date' => ['lit' => new DateTimeImmutable('2024-01-01T10:00:00+00:00')], 'amount' => 1, 'unit' => 'days']],
			'dateAdd weeks' => ['dateAdd' => ['date' => '2024-01-01', 'amount' => 2, 'unit' => 'weeks']], 'dateAdd months' => ['dateAdd' => ['date' => '2024-01-31', 'amount' => 1, 'unit' => 'months']],
			'dateAdd years' => ['dateAdd' => ['date' => '2024-02-29', 'amount' => 1, 'unit' => 'years']], 'dateAdd bad unit' => ['dateAdd' => ['date' => '2024-01-01', 'amount' => 1, 'unit' => 'ages']],
			'dateAdd text amount' => ['dateAdd' => ['date' => '2024-01-01', 'amount' => 'x', 'unit' => 'days']], 'dateAdd negative' => ['dateAdd' => ['date' => '2024-01-01', 'amount' => -1, 'unit' => 'days']],
			'dateAdd float' => ['dateAdd' => ['date' => '2024-01-01', 'amount' => 1.9, 'unit' => 'days']],
			'max' => ['max' => [1, '3', null, 2.5]], 'max equal' => ['max' => [1, 1.0, '1']], 'max int first' => ['max' => [1, 1.0]], 'max float first' => ['max' => [1.0, 1]], 'max none' => ['max' => [null]], 'max empty' => ['max' => []], 'max bare' => ['max' => 1], 'max text' => ['max' => [1, 'x']],
			'min' => ['min' => [3, null, '1', 2.5]], 'min equal' => ['min' => [1.0, 1]], 'min int first' => ['min' => [1, 1.0]], 'min none' => ['min' => []], 'min text' => ['min' => ['x']], 'min bare' => ['min' => 'x'],
			'coalesce' => ['coalesce' => [null, $p('gone'), 0, 'x']], 'coalesce none' => ['coalesce' => [null]], 'coalesce bare' => ['coalesce' => 1],
			'abs' => ['abs' => [-3]], 'abs bare' => ['abs' => '-2.5'], 'abs null' => ['abs' => [null]], 'abs text' => ['abs' => ['x']], 'abs dict' => ['abs' => ['prop' => 'n']], 'abs none' => ['abs' => []],
			'round' => ['round' => [2.567, 2]], 'round default' => ['round' => ['2.5']], 'round null' => ['round' => [null, 'x']], 'round text' => ['round' => ['x']], 'round bare' => ['round' => 1],
			'round bad precision' => ['round' => [1.5, 'x']], 'round text precision' => ['round' => [1.5, '1']], 'round negative' => ['round' => [1250, -2]], 'round empty' => ['round' => []], 'round text then bad' => ['round' => ['x', 'y']],
			'year' => ['year' => ['2024-05-05']], 'year bare' => ['year' => $p('start')], 'year bad' => ['year' => ['x']], 'year none' => ['year' => []],
			'monthsElapsed' => ['monthsElapsed' => ['2024-03-15', '2023-01-01']], 'monthsElapsed negative' => ['monthsElapsed' => ['2023-01-01', '2024-03-15']], 'monthsElapsed bad' => ['monthsElapsed' => ['x', '2023-01-01']],
			'monthsElapsed bad second' => ['monthsElapsed' => ['2023-01-01', 'x']], 'monthsElapsed short' => ['monthsElapsed' => ['2023-01-01']], 'monthsElapsed bare' => ['monthsElapsed' => 'x'], 'monthsElapsed now' => ['monthsElapsed' => ['now', 'now']],
			'graduated' => ['graduated' => [15, $p('bands')]], 'graduated cap' => ['graduated' => [15, $p('bands'), 20]], 'graduated null cap' => ['graduated' => [15, $p('bands'), null]], 'graduated null' => ['graduated' => [null, $p('bands')]],
			'graduated short' => ['graduated' => [1]], 'graduated dict' => ['graduated' => ['a' => 1, 'b' => 2]], 'graduated bare' => ['graduated' => 1], 'graduated bad' => ['graduated' => ['x', $p('bands')]],
			'bandRate' => ['bandRate' => [5, $p('bands')]], 'bandRate high' => ['bandRate' => [50, $p('bands'), 1]], 'bandRate short' => ['bandRate' => [1]], 'bandRate null bands' => ['bandRate' => [5, null]],
			'sha256' => ['sha256' => ['x']], 'sha256 bare' => ['sha256' => $p('n')], 'sha256 null' => ['sha256' => [null]],
			'sequence bare' => ['sequence' => 'x'], 'sequence no scope' => ['sequence' => ['pad' => 2]], 'sequence bad scope' => ['sequence' => ['scope' => 'daily']],
			'sequence read path' => ['sequence' => ['scope' => 'global']], 'nested' => ['concat' => [['round' => [['/' => [10, 4]], 1]], ['if' => [['gt' => [$p('n'), 3]], ' big', ' small']]]],
		];
		foreach (['eq', 'ne', 'lt', 'lte', 'gt', 'gte'] as $op) {
			$out[$op.' bare'] = [$op => 1];
			$out[$op.' short'] = [$op => [1]];
			$pairs = [[1, 2], [2, 2], [3, 2], [1, '1'], [null, 1], [1, null], [null, null], ['a', 'b'], ['2024-01-02', '2024-01-01'], ['2024-01-01', '2024-01-01T00:00:00Z'], ['2024-99-99', 'x']];
			foreach ($pairs as $i => $pair) {
				$out[$op.' '.$i] = [$op => $pair];
			}
			$out[$op.' dates'] = [$op => [['lit' => new DateTimeImmutable('2024-01-01')], '2024-01-02']];
			$out[$op.' dates later'] = [$op => [['lit' => new DateTimeImmutable('2024-01-03')], '2024-01-02']];
			$out[$op.' dates equal'] = [$op => [['lit' => new DateTimeImmutable('2024-01-02T00:00:00+00:00')], '2024-01-02T00:00:00+00:00']];
			$out[$op.' arrays'] = [$op => [['lit' => ['a' => 1]], ['lit' => ['b' => 1]]]];
		}
		foreach (['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds'] as $unit) {
			$out['dateDiff '.$unit] = ['dateDiff' => ['from' => '2022-01-15T00:00:00Z', 'to' => '2024-03-20T06:30:10Z', 'unit' => $unit]];
			$out['dateDiff back '.$unit] = ['dateDiff' => ['from' => '2024-03-20T06:30:10Z', 'to' => '2022-01-15T00:00:00Z', 'unit' => $unit]];
		}
		return $out;
	}//end expressions()

	/**
	 * What an expression evaluates to, in a recordable form.
	 *
	 * @param CalculationEvaluator $evaluator  The evaluator.
	 * @param mixed                $expression The expression.
	 *
	 * @return array<string, mixed>
	 */
	private function answer(CalculationEvaluator $evaluator, mixed $expression): array {
		try {
			$value = $evaluator->evaluate(self::OBJECT, $expression);
			return ['type' => get_debug_type($value), 'value' => is_float($value) ? var_export($value, true) : $value];
		} catch (Throwable $e) {
			return ['thrown' => get_class($e), 'message' => $e->getMessage()];
		}
	}//end answer()

	/**
	 * A real sequence context over a recording reservation.
	 *
	 * @param CalculationEvaluator $evaluator The evaluator.
	 *
	 * @return array<string, mixed>
	 */
	private function sequenceAnswers(CalculationEvaluator $evaluator): array {
		$sequences = $this->createMock(SequenceService::class);
		$scopes = [];
		$sequences->method('reserveNext')->willReturnCallback(
			static function (SequenceScope $sequence) use (&$scopes): int {
				[$registerId, $schemaId, $scopeKey] = [$sequence->registerId, $sequence->schemaId, $sequence->scopeKey];
				$scopes[] = (string)preg_replace('/\d/', '9', $scopeKey);
				return 42;
			}
		);
		$context = new SequenceContext($sequences, 1, 2);
		$out = [];
		$nodes = [
			'default' => ['scope' => 'global'], 'yearly' => ['scope' => 'yearly', 'pad' => 6], 'monthly' => ['scope' => 'monthly', 'pad' => '2'],
			'text pad' => ['scope' => 'global', 'pad' => 'x'], 'pad expr' => ['scope' => ['lit' => 'global'], 'pad' => ['+' => [1, 2]]],
		];
		foreach ($nodes as $label => $args) {
			$out[$label] = $evaluator->evaluate(self::OBJECT, ['concat' => ['#', ['sequence' => $args]]], $context);
		}
		$out['context gone after'] = $evaluator->evaluate(self::OBJECT, ['sequence' => ['scope' => 'global']]);
		try {
			$evaluator->evaluate(self::OBJECT, ['nope' => []], $context);
		} catch (Throwable $e) {
			$out['context gone after a throw'] = $evaluator->evaluate(self::OBJECT, ['sequence' => ['scope' => 'global']]);
		}
		$out['scopes'] = $scopes;
		return $out;
	}//end sequenceAnswers()

	/**
	 * The expression walkers.
	 *
	 * @param CalculationEvaluator $evaluator The evaluator.
	 *
	 * @return array<string, mixed>
	 */
	private function walkers(CalculationEvaluator $evaluator): array {
		$tree = ['if' => [['prop' => 'a'], ['+' => [['prop' => ['b']], ['prop' => 'a']]], ['concat' => [['prop' => ''], ['prop' => 3], ['prop' => [7]], ['prop' => []], ['sequence' => ['scope' => 'global']]]]]];
		return [
			'references' => $evaluator->referencedProperties($tree),
			'references scalar' => $evaluator->referencedProperties('x'),
			'references prop first' => $evaluator->referencedProperties(['prop' => ['prop' => 'inner']]),
			'uses sequence' => $evaluator->expressionUsesSequence($tree),
			'uses no sequence' => $evaluator->expressionUsesSequence(['+' => [1, 2]]),
			'uses scalar' => $evaluator->expressionUsesSequence('sequence'),
		];
	}//end walkers()

	/**
	 * Every expression answers as recorded.
	 *
	 * @return void
	 */
	public function testEvaluatorMatchesTheRecording(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$evaluator = new CalculationEvaluator(new PlaceholderResolver($session));
		$seen = [];
		foreach ($this->expressions() as $label => $expression) {
			$seen[$label] = $this->answer($evaluator, $expression);
		}
		$seen['sequence'] = $this->sequenceAnswers($evaluator);
		$seen['walkers'] = $this->walkers($evaluator);

		$json = json_decode((string)json_encode($seen, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
		if (getenv('CALC_EVAL_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
			$this->markTestIncomplete('recorded');
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $json);
	}//end testEvaluatorMatchesTheRecording()
}//end class
