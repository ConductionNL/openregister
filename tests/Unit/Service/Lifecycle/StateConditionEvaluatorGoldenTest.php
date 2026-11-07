<?php

/**
 * Golden test for StateConditionEvaluator.
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

use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Lifecycle\StateConditionEvaluator;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\ConditionTracer;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for StateConditionEvaluator::refusal().
 *
 * Each annotation is asked about every move between `open`, `besloten`,
 * `gesloten`, `onbekend`, the empty string and null, for three objects. The
 * full grid of refusals is compared with fixtures/state-condition-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\StateConditionEvaluator
 */
class StateConditionEvaluatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/state-condition-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$base = ['field' => 'status', 'initial' => 'open'];
		$states = static fn (array $states): array => [$base + ['states' => $states]];
		return [
			'no states' => [$base],
			'bare entry and exit' => $states(
				[
					'besloten' => ['entry' => ['!!' => ['var' => 'object.besluit']]],
					'gesloten' => ['exit' => ['!!' => ['var' => 'object.grond']]],
				]
			),
			'declared messages' => $states(
				[
					'besloten' => ['entry' => ['!!' => ['var' => 'object.besluit']], 'entryMessage' => 'Neem eerst een besluit.'],
					'gesloten' => ['exit' => ['!!' => ['var' => 'object.grond']], 'exitMessage' => ''],
				]
			),
			'nested shape' => $states(
				[
					'besloten' => ['entry' => ['condition' => ['!!' => ['var' => 'object.besluit']], 'message' => 'Eigen tekst.'], 'entryMessage' => 'Blok tekst.'],
					'gesloten' => ['exit' => ['when' => ['==' => [['var' => 'object.grond'], 'ja']], 'message' => ''], 'exitMessage' => 'Blok exit.'],
					'open' => ['exit' => ['condition' => [], 'when' => ['!!' => ['var' => 'object.titel']]]],
				]
			),
			'and names the failing clause' => $states(
				[
					'besloten' => [
						'entry' => [
							'and' => [['!!' => ['var' => 'object.besluit']], ['==' => [['var' => 'object.grond'], 'ja']], ['!!' => ['var' => 'object.titel']]],
						],
					],
				]
			),
			'or names the first clause' => $states(
				['besloten' => ['entry' => ['or' => [['!!' => ['var' => ['object.titel', null]]], ['!!' => ['prop' => 'object.besluit']]]]]]
			),
			'reads nothing' => $states(['besloten' => ['entry' => ['==' => [1, 2]]], 'gesloten' => ['exit' => ['and' => [true, false]]]]),
			'and of non-list' => $states(['besloten' => ['entry' => ['and' => ['!!' => ['var' => 'object.besluit']]]]]),
			'multi-key node' => $states(['besloten' => ['entry' => ['!!' => ['var' => ''], 'x' => ['var' => 'object.grond']]]]),
			'scalar and empty declarations' => $states(['besloten' => ['entry' => true], 'gesloten' => ['exit' => []], 'open' => 'x']),
			'disabled block' => $states(['besloten' => ['enabled' => false, 'entry' => ['!!' => ['var' => 'object.besluit']]]]),
		];
	}//end cases()

	/**
	 * Compare every move of one annotation with the recording.
	 *
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testMatchesGolden(array $annotation): void {
		$name        = $this->dataName();
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$dialect   = new ConditionDialect(new CalculationEvaluator(new PlaceholderResolver($userSession)));
		$resolver  = new StateFieldRuleResolver($userSession, $this->createMock(IGroupManager::class), $dialect);
		$evaluator = new StateConditionEvaluator($resolver, $dialect, new ConditionTracer($dialect));

		$objects = [
			'empty' => [],
			'half' => ['besluit' => 'toegekend', 'grond' => 'nee'],
			'full' => ['besluit' => 'toegekend', 'grond' => 'ja', 'titel' => 'Dossier'],
		];
		$actual = [];
		foreach ($objects as $objectName => $object) {
			foreach (['open', 'besloten', 'gesloten', 'onbekend', '', null] as $from) {
				foreach (['open', 'besloten', 'gesloten', '', null] as $to) {
					$key          = sprintf('%s: %s -> %s', $objectName, var_export($from, true), var_export($to, true));
					$actual[$key] = $evaluator->refusal($annotation, ['status' => $to] + $object, $from, $to);
				}
			}
		}

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testMatchesGolden()
}//end class
