<?php

/**
 * Golden test for LifecycleStateValidator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\LifecycleStateValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for LifecycleStateValidator::validateStates() and validateInputsAgainstHiddenFields().
 *
 * Each case is a `states` block (and optionally transitions) on a schema that
 * declares status, title, owner and amount. The full error list, order
 * included, is compared with fixtures/lifecycle-states-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleStateValidator
 * @uses   \OCA\OpenRegister\Service\Lifecycle\StateFieldRuleScope
 */
class LifecycleStateValidatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/lifecycle-states-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$entry = static fn (array $extra = []): array => ['open' => ['fields' => ['hidden' => [['fields' => ['title']] + $extra]]]];
		$cases = [
			'no states' => ['annotation' => []],
			'states scalar' => ['annotation' => ['states' => 'all']],
			'states empty' => ['annotation' => ['states' => []]],
			'state outside enum' => ['annotation' => ['states' => ['gone' => ['fields' => []]]]],
			'state outside enum without enum set' => ['annotation' => ['states' => ['gone' => []]], 'enumSet' => null],
			'numeric state key' => ['annotation' => ['states' => [5 => ['fields' => ['hidden' => ['x']]]]], 'enumSet' => null],
			'state block scalar' => ['annotation' => ['states' => ['open' => 'yes']]],
			'state without fields' => ['annotation' => ['states' => ['open' => []]]],
			'fields scalar' => ['annotation' => ['states' => ['open' => ['fields' => 'title']]]],
			'fields empty' => ['annotation' => ['states' => ['open' => ['fields' => []]]]],
			'unknown kind' => ['annotation' => ['states' => ['open' => ['fields' => ['frozen' => [['fields' => ['title']]]]]]]],
			'numeric kind' => ['annotation' => ['states' => ['open' => ['fields' => [0 => [['fields' => ['title']]]]]]]],
			'kind entries scalar' => ['annotation' => ['states' => ['open' => ['fields' => ['readOnly' => 'title']]]]],
			'kind entries empty' => ['annotation' => ['states' => ['open' => ['fields' => ['required' => []]]]]],
			'entry scalar' => ['annotation' => ['states' => ['open' => ['fields' => ['hidden' => ['title']]]]]],
			'entry null' => ['annotation' => ['states' => ['open' => ['fields' => ['hidden' => [null]]]]]],
			'entry without names' => ['annotation' => ['states' => ['open' => ['fields' => ['hidden' => [['when' => ['var' => 'title']]]]]]]],
			'entry names empty list' => ['annotation' => ['states' => ['open' => ['fields' => ['hidden' => [['fields' => []]]]]]]],
			'entry names number' => ['annotation' => ['states' => ['open' => ['fields' => ['hidden' => [['fields' => 7]]]]]]],
			'entry valid' => ['annotation' => ['states' => $entry()]],
			'entry field alias string' => ['annotation' => ['states' => ['open' => ['fields' => ['readOnly' => [['field' => 'owner']]]]]]],
			'entry fields string' => ['annotation' => ['states' => ['open' => ['fields' => ['required' => [['fields' => 'owner']]]]]]],
			'entry fields wins over field' => ['annotation' => ['states' => ['open' => ['fields' => ['hidden' => [['fields' => ['nope'], 'field' => 'title']]]]]]],
			'entry empty string field' => ['annotation' => ['states' => ['open' => ['fields' => ['hidden' => [['fields' => '']]]]]]],
			'entry bad names' => ['annotation' => ['states' => ['open' => ['fields' => ['hidden' => [['fields' => ['', 5, 'nope', 'title', null, 'gone']]]]]]]],
			'groups string' => ['annotation' => ['states' => $entry(['groups' => 'admin'])]],
			'groups list' => ['annotation' => ['states' => $entry(['groups' => ['admin', 'staff']])]],
			'groups empty list' => ['annotation' => ['states' => $entry(['groups' => []])]],
			'groups number' => ['annotation' => ['states' => $entry(['groups' => 3])]],
			'groups null' => ['annotation' => ['states' => $entry(['groups' => null])]],
			'groups bad member' => ['annotation' => ['states' => $entry(['groups' => ['admin', '', 4]])]],
			'groups bad member first' => ['annotation' => ['states' => $entry(['groups' => [4, 'admin']])]],
			'when valid' => ['annotation' => ['states' => $entry(['when' => ['==' => [['var' => 'amount'], 1]]])]],
			'when undeclared' => ['annotation' => ['states' => $entry(['when' => ['==' => [['var' => 'nope'], ['prop' => 'object.other']]]])]],
			'when scalar' => ['annotation' => ['states' => $entry(['when' => true])]],
			'when empty' => ['annotation' => ['states' => $entry(['when' => []])]],
			'condition alias' => ['annotation' => ['states' => $entry(['condition' => ['var' => ['ghost', 0]]])]],
			'when wins over condition' => ['annotation' => ['states' => $entry(['when' => ['var' => 'amount'], 'condition' => ['var' => 'ghost']])]],
			'when built-ins' => ['annotation' => ['states' => $entry(['when' => ['and' => [['var' => 'user.id'], ['var' => '@self.owner'], ['var' => '_x'], ['var' => 'object'], ['var' => ' '], ['var' => 'object.@x'], ['prop' => 'state.name']]]])]],
			'every entry problem' => [
				'annotation' => ['states' => ['open' => ['fields' => ['hidden' => [['fields' => ['nope', 3], 'groups' => [], 'when' => 'x']]]]]],
			],
			'many entries and kinds' => [
				'annotation' => [
					'states' => [
						'open' => ['fields' => ['hidden' => [['fields' => ['title']], 'bad', ['fields' => ['gone']]], 'required' => [['field' => 'owner', 'when' => ['var' => 'zip']]], 'odd' => []]],
						'closed' => ['fields' => ['readOnly' => [['fields' => ['amount'], 'groups' => ['x', '']]]], 'entry' => ['var' => 'nope']],
					],
				],
			],
			'entry exit condition' => ['annotation' => ['states' => ['open' => ['entry' => ['var' => 'title'], 'exit' => ['var' => 'ghost'], 'condition' => 'yes']]]],
			'nested condition' => ['annotation' => ['states' => ['open' => ['entry' => ['condition' => ['var' => 'ghost']], 'exit' => ['when' => ['var' => 'other']]]]]],
			'nested condition empty' => ['annotation' => ['states' => ['open' => ['entry' => ['condition' => []], 'exit' => ['when' => 'x']]]]],
			'no properties' => ['annotation' => ['states' => $entry()], 'properties' => null],
			'properties scalar' => ['annotation' => ['states' => $entry()], 'properties' => 'x'],
		];

		$hidden = ['closed' => ['fields' => ['hidden' => [['fields' => ['title', '', 4]], ['field' => 'owner'], ['fields' => 'amount'], 'bad', ['fields' => 9], ['when' => 1]]]]];
		$inputs = [
			'inputs vs hidden' => ['states' => $hidden, 'transitions' => ['close' => ['to' => 'closed', 'inputs' => ['title', ['field' => 'owner'], ['field' => 5], 'amount', 'status', 7, '']]]],
			'inputs no states' => ['states' => null, 'transitions' => ['close' => ['to' => 'closed', 'inputs' => ['title']]]],
			'inputs transitions scalar' => ['states' => $hidden, 'transitions' => 'x'],
			'inputs target without hidden' => ['states' => ['closed' => ['fields' => ['readOnly' => [['fields' => ['title']]]]]], 'transitions' => ['close' => ['to' => 'closed', 'inputs' => ['title']]]],
			'inputs target block scalar' => ['states' => ['closed' => 'x'], 'transitions' => ['close' => ['to' => 'closed', 'inputs' => ['title']]]],
			'inputs hidden scalar' => ['states' => ['closed' => ['fields' => ['hidden' => 'title']]], 'transitions' => ['close' => ['to' => 'closed', 'inputs' => ['title']]]],
			'inputs bad spec' => ['states' => $hidden, 'transitions' => ['a' => 'x', 'b' => ['to' => 'closed'], 'c' => ['to' => 'closed', 'inputs' => 'title'], 9 => ['inputs' => ['title']]]],
		];

		$out = [];
		foreach ($cases as $name => $case) {
			$out['states: ' . $name] = [$case];
		}

		foreach ($inputs as $name => $case) {
			$out['inputs: ' . $name] = [['inputs' => $case]];
		}

		return $out;
	}//end cases()

	/**
	 * Compare the validator's errors for one case with the recording.
	 *
	 * @param array<string, mixed> $case The case: a states annotation, or inputs with states and transitions.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testMatchesGolden(array $case): void {
		$name = $this->dataName();
		$validator = new LifecycleStateValidator();
		$properties = [
			'status' => ['type' => 'string'],
			'title' => ['type' => 'string'],
			'owner' => ['type' => 'string'],
			'amount' => ['type' => 'number'],
		];
		if (array_key_exists('properties', $case) === true) {
			$properties = $case['properties'];
		}

		$enumSet = ['open' => 0, 'closed' => 1];
		if (array_key_exists('enumSet', $case) === true) {
			$enumSet = $case['enumSet'];
		}

		if (isset($case['inputs']) === true) {
			$annotation = [];
			if ($case['inputs']['states'] !== null) {
				$annotation['states'] = $case['inputs']['states'];
			}

			$actual = $validator->validateInputsAgainstHiddenFields($annotation, $case['inputs']['transitions']);
		} else {
			$schema = ['properties' => $properties];
			if ($properties === null) {
				$schema = [];
			}

			$actual = $validator->validateStates($case['annotation'], $schema, $enumSet);
		}

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testMatchesGolden()
}//end class
