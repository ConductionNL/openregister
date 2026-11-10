<?php

/**
 * Golden test for LifecycleTransitionsValidator.
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

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\LifecycleGraph;
use OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionsValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for LifecycleTransitionsValidator::validate().
 *
 * Each case is validated without a guard catalogue and with one, and the
 * two full error lists, order included, are compared with
 * fixtures/lifecycle-transitions-golden.json. Set UPDATE_GOLDEN=1 to rewrite
 * the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionsValidator
 */
class LifecycleTransitionsValidatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/lifecycle-transitions-golden.json';

	/**
	 * @return array<string, array{0: array<int, mixed>, 1: string|null, 2: array<int|string, mixed>}>
	 */
	public static function cases(): array {
		$states = ['open', ['name' => 'review'], 'closed'];
		return [
			'empty everything' => [[], null, []],
			'empty initial string' => [['open'], '', []],
			'initial undeclared' => [['open'], 'gone', []],
			'initial with no states' => [[], 'open', []],
			'odd state entries' => [['', null, 5, ['name' => ''], ['label' => 'x'], ['name' => 'open'], 'open'], 'open', []],
			'valid map' => [
				$states,
				'open',
				['submit' => ['from' => 'open', 'to' => 'review'], 'close' => ['from' => ['open', 'review'], 'to' => 'closed']],
			],
			'valid list' => [$states, 'open', [['from' => 'open', 'to' => 'review'], ['from' => 'review', 'to' => 'closed']]],
			'unreachable states' => [$states, 'open', ['submit' => ['from' => 'open', 'to' => 'open']]],
			'unreachable initial only' => [['open'], 'open', []],
			'transition scalars' => [$states, 'open', ['a' => 'x', 3 => null, 'b' => []]],
			'from shapes' => [
				$states,
				'open',
				[
					'empty list' => ['from' => [], 'to' => 'closed'],
					'blank' => ['from' => '', 'to' => 'closed'],
					'number' => ['from' => 4, 'to' => 'closed'],
					'bad member' => ['from' => ['open', ''], 'to' => 'closed'],
					'non-string member' => ['from' => ['open', 3], 'to' => 'closed'],
					'keyed list' => ['from' => ['x' => 'review', 'y' => 'gone'], 'to' => 'closed'],
				],
			],
			'to shapes' => [
				$states,
				'open',
				[
					'blank' => ['from' => 'open', 'to' => ''],
					'list' => ['from' => 'open', 'to' => ['review']],
					'missing' => ['from' => 'open'],
				],
			],
			'undeclared endpoints' => [
				$states,
				'open',
				['go' => ['from' => ['gone', 'open', 'lost'], 'to' => 'nowhere'], 1 => ['from' => 'nope', 'to' => 'review']],
			],
			'guards shapes' => [
				$states,
				'open',
				[
					'scalar' => ['from' => 'open', 'to' => 'review', 'guards' => 'isOwner'],
					'null' => ['from' => 'open', 'to' => 'review', 'guards' => null],
					'empty' => ['from' => 'open', 'to' => 'review', 'guards' => []],
					'mixed' => ['from' => 'review', 'to' => 'closed', 'guards' => ['isOwner', '', 7, 'hasFile', null, 'nope']],
				],
			],
			'everything wrong at once' => [[], null, ['x' => ['from' => 3, 'to' => 4, 'guards' => 'g'], 0 => 'y']],
		];
	}//end cases()

	/**
	 * Compare both validations of one case with the recording.
	 *
	 * @param array<int, mixed>        $states      The states.
	 * @param string|null              $initial     The initial state.
	 * @param array<int|string, mixed> $transitions The transitions.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testMatchesGolden(array $states, ?string $initial, array $transitions): void {
		$name      = $this->dataName();
		$validator = new LifecycleTransitionsValidator();
		$actual    = [
			'no catalogue' => $validator->validate(new LifecycleGraph($states, $initial, $transitions)),
			'catalogue' => $validator->validate(new LifecycleGraph($states, $initial, $transitions, ['isOwner', 'hasFile'])),
			'empty catalogue' => $validator->validate(new LifecycleGraph($states, $initial, $transitions, [])),
		];

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
