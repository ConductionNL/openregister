<?php

/**
 * Golden test for AutoTransitionSelector.
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
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionSelector;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionWrite;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionReporting;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Golden test for AutoTransitionSelector::select() and declaresAutoWhen().
 *
 * Each annotation is asked from three current states. The candidate, every
 * warning the selector and the evaluator log, and the declaresAutoWhen()
 * answer are compared with fixtures/auto-transition-golden.json. Set
 * UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\AutoTransitionSelector
 * @covers \OCA\OpenRegister\Service\Lifecycle\AutoTransitionContext
 * @uses   \OCA\OpenRegister\Service\Lifecycle\AutoTransitionCandidate
 * @uses   \OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator
 * @uses \OCA\OpenRegister\Service\Rules\ConditionDialect
 * @uses \OCA\OpenRegister\Service\Calculation\CalculationEvaluator
 * @uses \OCA\OpenRegister\Search\Service\Search\PlaceholderResolver
 * @uses \OCA\OpenRegister\Service\Flow\FlowExpression
 */
class AutoTransitionSelectorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/auto-transition-golden.json';

	private const HOLDS = ['!!' => ['var' => 'object.motivering']];

	private const FAILS = ['!!' => ['var' => 'object.ontbreekt']];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$with = static fn (array $transitions): array => [['field' => 'status', 'transitions' => $transitions]];
		return [
			'no field' => [['transitions' => ['a' => ['from' => 'open', 'to' => 'dicht', 'autoWhen' => self::HOLDS]]]],
			'property alias' => [['property' => 'status', 'transitions' => ['a' => ['from' => 'open', 'to' => 'dicht', 'autoWhen' => self::HOLDS]]]],
			'transitions scalar' => [['field' => 'status', 'transitions' => 'x']],
			'transitions empty' => $with([]),
			'one holds' => $with(['sluiten' => ['from' => ['open'], 'to' => 'dicht', 'autoWhen' => self::HOLDS]]),
			'string from' => $with(['sluiten' => ['from' => 'open', 'to' => 'dicht', 'autoWhen' => self::HOLDS]]),
			'rule fails' => $with(['sluiten' => ['from' => 'open', 'to' => 'dicht', 'autoWhen' => self::FAILS]]),
			'scalar rule' => $with(['sluiten' => ['from' => 'open', 'to' => 'dicht', 'autoWhen' => true]]),
			'no autoWhen' => $with(['sluiten' => ['from' => 'open', 'to' => 'dicht'], 'x' => 'y']),
			'to equals current' => $with(['blijven' => ['from' => 'open', 'to' => 'open', 'autoWhen' => self::HOLDS]]),
			'to missing' => $with(['leeg' => ['from' => 'open', 'autoWhen' => self::HOLDS]]),
			'from scalar number' => $with(['raar' => ['from' => 5, 'to' => 'dicht', 'autoWhen' => self::HOLDS]]),
			'ambiguous' => $with(
				[
					'a' => ['from' => 'open', 'to' => 'dicht', 'autoWhen' => self::HOLDS],
					'b' => ['from' => ['open', 'wacht'], 'to' => 'wacht', 'autoWhen' => self::HOLDS],
					'c' => ['from' => 'open', 'to' => 'elders', 'autoWhen' => self::FAILS],
				]
			),
			'async mode' => $with(['sluiten' => ['from' => 'open', 'to' => 'dicht', 'autoWhen' => self::HOLDS, 'executionMode' => 'async']]),
			'bad mode' => $with(['sluiten' => ['from' => 'open', 'to' => 'dicht', 'autoWhen' => self::HOLDS, 'executionMode' => 'SYNC']]),
			'null mode' => $with(['sluiten' => ['from' => 'open', 'to' => 'dicht', 'autoWhen' => self::HOLDS, 'executionMode' => null]]),
			'shadowed' => $with(
				[
					'junk' => 'x',
					'hand' => ['from' => ['open'], 'to' => 'dicht'],
					'auto' => ['from' => 'open', 'to' => 'dicht', 'autoWhen' => self::HOLDS],
				]
			),
			'later twin does not shadow' => $with(
				[
					'auto' => ['from' => 'open', 'to' => 'dicht', 'autoWhen' => self::HOLDS],
					'hand' => ['from' => 'open', 'to' => 'dicht'],
				]
			),
			'earlier other pair does not shadow' => $with(
				[
					'other' => ['from' => 'wacht', 'to' => 'dicht'],
					'otherTo' => ['from' => 'open', 'to' => 'elders'],
					'noTo' => ['from' => 'open'],
					'auto' => ['from' => 'open', 'to' => 'dicht', 'autoWhen' => self::HOLDS],
				]
			),
			'numeric actions' => $with([['from' => 'open', 'to' => 'dicht', 'autoWhen' => self::HOLDS]]),
		];
	}//end cases()

	/**
	 * Compare the selection of one annotation, from three states, with the recording.
	 *
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testMatchesGolden(array $annotation): void {
		$name     = $this->dataName();
		$log      = new class extends AbstractLogger {
			/** @var array<int, array<string, mixed>> */
			public array $lines = [];

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}
		};
		$selector = new AutoTransitionSelector($this->evaluator($log), $log);

		$actual = ['declaresAutoWhen' => $selector->declaresAutoWhen($annotation)];
		foreach (['open', 'wacht', ''] as $current) {
			$log->lines = [];
			$candidate  = $selector->select($annotation, new AutoTransitionWrite(['status' => $current, 'motivering' => 'Ja.'], ['status' => 'vorig'], 'bezwaar'));
			$result     = null;
			if ($candidate !== null) {
				$result = [$candidate->action, $candidate->from, $candidate->to, $candidate->mode, $candidate->isSync()];
			}

			$actual['from ' . $current] = ['candidate' => $result, 'log' => $log->lines];
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

	/**
	 * A real evaluator over the real dialect, logging into the same recorder.
	 *
	 * @param AbstractLogger $log The recorder.
	 *
	 * @return LifecycleConditionEvaluator The evaluator.
	 */
	private function evaluator(AbstractLogger $log): LifecycleConditionEvaluator {
		$userSession  = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn([]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('en');
		$dialect = new ConditionDialect(new CalculationEvaluator(new PlaceholderResolver($userSession)));

		return new LifecycleConditionEvaluator(new LifecycleConditionReporting($l10n, $log), new \OCA\OpenRegister\Service\Lifecycle\LifecycleRuleCheck($dialect, new \OCA\OpenRegister\Service\Lifecycle\LifecycleRuleCaller($userSession, $groupManager)));
	}//end evaluator()
}//end class
