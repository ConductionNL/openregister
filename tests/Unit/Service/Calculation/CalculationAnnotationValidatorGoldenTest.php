<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for CalculationAnnotationValidator::validate().
 *
 * Each case is a schema; the error list it yields is compared with
 * fixtures/calculation-annotation-golden.json. Set UPDATE_GOLDEN=1 to
 * rewrite the fixture.
 */
class CalculationAnnotationValidatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/calculation-annotation-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$props = ['amount' => [], 'start' => [], 'end' => [], 'qty' => []];
		$calc = static fn (array $calcs, array $extra = []): array => [
			['properties' => $props, 'x-openregister-calculations' => $calcs] + $extra,
		];
		$num = static fn (mixed $expr): array => ['type' => 'number', 'expression' => $expr];
		$refs = [
			'x-openregister-references' => ['owner' => ['schema' => 'person', 'mode' => 'relatedObject', 'field' => 'amount']],
			'x-openregister-aggregate-refs' => ['total' => ['schema' => 'line', 'metric' => 'sum', 'field' => 'amount']],
		];

		return [
			'no-key' => [['properties' => $props]],
			'empty' => $calc([]),
			'not-array' => [['x-openregister-calculations' => 'x']],
			'no-properties' => [['x-openregister-calculations' => ['a' => $num(['prop' => 'amount'])]]],
			'properties-not-array' => [['properties' => 'x', 'x-openregister-calculations' => ['a' => $num(['lit' => 1])]]],
			'bad-name-int' => $calc([0 => $num(['lit' => 1]), 'ok' => $num(['lit' => 2])]),
			'malformed-spec' => $calc(['a' => 'x', 'b' => $num(['lit' => 1])]),
			'bad-type' => $calc(['a' => ['type' => 'money', 'expression' => ['lit' => 1]]]),
			'missing-type-and-expression' => $calc(['a' => []]),
			'no-expression' => $calc(['a' => ['type' => 'string']]),
			'depends-on' => $calc(['a' => $num(['prop' => 'amount']) + ['dependsOn' => ['amount']]]),
			'depends-on-null' => $calc(['a' => $num(['lit' => 1]) + ['dependsOn' => null]]),
			'scalar-expression' => $calc(['a' => $num(5)]),
			'multi-key' => $calc(['a' => $num(['lit' => 1, 'prop' => 'amount'])]),
			'empty-expr' => $calc(['a' => $num([])]),
			'unknown-op' => $calc(['a' => $num(['pow' => [1, 2]])]),
			'prop-ok' => $calc(['a' => $num(['prop' => 'amount'])]),
			'prop-unknown' => $calc(['a' => $num(['prop' => 'nope'])]),
			'prop-empty' => $calc(['a' => $num(['prop' => ''])]),
			'prop-list' => $calc(['a' => $num(['prop' => ['amount', 'x']]), 'b' => $num(['prop' => ['nope']])]),
			'prop-empty-list' => $calc(['a' => $num(['prop' => []])]),
			'prop-int' => $calc(['a' => $num(['prop' => 5])]),
			'prop-calc' => $calc(['a' => $num(['prop' => 'b']), 'b' => $num(['prop' => 'amount'])]),
			'ref-ok' => $calc(['a' => $num(['prop' => '@ref.owner.name'])], $refs),
			'ref-unknown' => $calc(['a' => $num(['prop' => '@ref.boss.name'])], $refs),
			'ref-unknown-none-declared' => $calc(['a' => $num(['prop' => '@ref.boss'])]),
			'aggregate-ok' => $calc(['a' => $num(['prop' => '@aggregate.total'])], $refs),
			'aggregate-unknown' => $calc(['a' => $num(['prop' => '@aggregate.other.x'])], $refs),
			'self-ok' => $calc(['a' => $num(['prop' => '@self.created']), 'b' => $num(['prop' => '@self.owner'])]),
			'self-unknown' => $calc(['a' => $num(['prop' => '@self.secret'])]),
			'nested-list' => $calc(['a' => $num(['max' => [['prop' => 'amount'], ['prop' => 'nope'], ['pow' => 1], 3]])]),
			'nested-scalar-arg' => $calc(['a' => $num(['abs' => ['prop' => 'nope']])]),
			'nested-string-arg' => $calc(['a' => $num(['not' => 'x'])]),
			'date-diff-ok' => $calc(['a' => $num(['dateDiff' => ['from' => ['prop' => 'start'], 'to' => ['prop' => 'end'], 'unit' => 'days']])]),
			'date-diff-bad-unit' => $calc(['a' => $num(['dateDiff' => ['from' => ['prop' => 'start'], 'to' => ['prop' => 'zzz'], 'unit' => 'fortnights']])]),
			'date-diff-unit-int' => $calc(['a' => $num(['dateDiff' => ['from' => ['lit' => 1], 'to' => ['prop' => 'end'], 'unit' => 3]])]),
			'date-diff-missing-unit' => $calc(['a' => $num(['dateDiff' => ['from' => ['prop' => 'start'], 'to' => ['prop' => 'end']]])]),
			'date-diff-missing-from' => $calc(['a' => $num(['dateDiff' => ['to' => ['prop' => 'end'], 'unit' => 'days']])]),
			'date-diff-missing-to' => $calc(['a' => $num(['dateDiff' => ['from' => ['prop' => 'end'], 'unit' => 'days']])]),
			'date-diff-not-array' => $calc(['a' => $num(['dateDiff' => 'x'])]),
			'prop-empty-with-empty-property' => [['properties' => ['' => []], 'x-openregister-calculations' => ['a' => $num(['prop' => ''])]]],
			'cycle-after-finished-branch' => $calc(['a' => $num(['max' => [['prop' => 'x'], ['prop' => 'b']]]), 'b' => $num(['prop' => 'a']), 'x' => $num(['lit' => 1])]),
			'cycle-two' => $calc(['a' => $num(['prop' => 'b']), 'b' => $num(['prop' => 'a'])]),
			'cycle-self' => $calc(['a' => $num(['prop' => 'a'])]),
			'cycle-three' => $calc(['x' => $num(['prop' => 'amount']), 'a' => $num(['prop' => 'b']), 'b' => $num(['max' => [['prop' => 'c'], ['prop' => 'x']]]), 'c' => $num(['prop' => 'a'])]),
			'chain-no-cycle' => $calc(['a' => $num(['prop' => 'b']), 'b' => $num(['prop' => 'c']), 'c' => $num(['prop' => 'amount'])]),
			'diamond' => $calc(['a' => $num(['max' => [['prop' => 'b'], ['prop' => 'c']]]), 'b' => $num(['prop' => 'd']), 'c' => $num(['prop' => 'd']), 'd' => $num(['lit' => 1])]),
			'two-cycles-first-wins' => $calc(['p' => $num(['prop' => 'q']), 'q' => $num(['prop' => 'p']), 'r' => $num(['prop' => 'r'])]),
			'cycle-via-bad-calc' => $calc(['a' => $num(['prop' => 'b']), 'b' => ['type' => 'x']]),
			'refs-not-array' => $calc(['a' => $num(['lit' => 1])], ['x-openregister-references' => 'x', 'x-openregister-aggregate-refs' => 5]),
			'refs-empty' => $calc(['a' => $num(['lit' => 1])], ['x-openregister-references' => [], 'x-openregister-aggregate-refs' => []]),
			'refs-every-error' => $calc(['a' => $num(['prop' => '@ref.r7'])], ['x-openregister-references' => [
				0 => ['schema' => 's', 'mode' => 'lookup'],
				'r1' => 'x',
				'r2' => ['mode' => 'lookup', 'filters' => []],
				'r3' => ['schema' => 's', 'mode' => 'other'],
				'r4' => ['schema' => 's'],
				'r5' => ['schema' => 's', 'mode' => 'relatedObject'],
				'r6' => ['schema' => 's', 'mode' => 'relatedObject', 'field' => 'nope'],
				'r7' => ['schema' => 's', 'mode' => 'lookup'],
				'r8' => ['schema' => 's', 'mode' => 'lookup', 'filters' => 'x'],
				'r9' => ['schema' => 's', 'mode' => 'relatedObject', 'field' => 'qty'],
				'' => ['schema' => 's', 'mode' => 'lookup', 'filters' => []],
			]]),
			'aggregate-refs-every-error' => $calc(['a' => $num(['prop' => '@aggregate.g1'])], ['x-openregister-aggregate-refs' => [
				0 => ['schema' => 's', 'metric' => 'count'],
				'g0' => 'x',
				'g1' => ['metric' => 'count'],
				'g2' => ['schema' => 's', 'metric' => 'median', 'field' => 'amount'],
				'g3' => ['schema' => 's'],
				'g4' => ['schema' => 's', 'metric' => 'sum'],
				'g5' => ['schema' => 's', 'metric' => 'avg', 'field' => ''],
				'g6' => ['schema' => 's', 'metric' => 'max', 'field' => 'amount'],
				'g7' => ['schema' => '', 'metric' => 'min'],
			]]),
			'mixed-order' => $calc([
				'a' => ['type' => 'bad', 'expression' => ['prop' => 'nope'], 'dependsOn' => []],
				'b' => $num(['dateDiff' => ['from' => ['prop' => '@self.zz'], 'to' => ['pow' => 1], 'unit' => 'eons']]),
				'c' => $num(['prop' => 'b']),
				'd' => $num(['prop' => 'd']),
			], ['x-openregister-references' => ['r' => ['mode' => 'x']], 'x-openregister-aggregate-refs' => ['g' => ['metric' => 'sum']]]),
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testValidateMatchesTheGolden(array $schema): void {
		$name = (string)$this->dataName();
		$actual = (new CalculationAnnotationValidator())->validate(schema: $schema);
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $golden, 'no golden recorded for ' . $name);
		$this->assertSame($golden[$name], $actual);
	}//end testValidateMatchesTheGolden()
}//end class
