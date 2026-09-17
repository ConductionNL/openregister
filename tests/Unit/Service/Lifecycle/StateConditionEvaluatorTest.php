<?php

/**
 * StateConditionEvaluator tests.
 *
 * A transition `condition` guards one edge; a state's `entry` and `exit`
 * conditions guard the state itself. What is asserted here is the difference
 * that makes:
 *  - one entry condition refuses on EVERY path into the state, so the three
 *    transitions that reach it are three refusals and not one guarded edge
 *    plus two open ones;
 *  - an exit condition refuses on the way out, before the entry half runs;
 *  - the refusal names the clause that failed, including inside an `and`;
 *  - a move that changes nothing is neither an entry nor an exit.
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
 * @spec openspec/changes/field-rules-by-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Lifecycle\StateConditionEvaluator;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\ConditionTracer;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Lifecycle\StateConditionEvaluator
 */
class StateConditionEvaluatorTest extends TestCase {

	private StateConditionEvaluator $evaluator;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$groupManager = $this->createMock(IGroupManager::class);

		$dialect = new ConditionDialect(new CalculationEvaluator(new PlaceholderResolver($userSession)));
		$resolver = new StateFieldRuleResolver($userSession, $groupManager, $dialect);

		$this->evaluator = new StateConditionEvaluator($resolver, $dialect, new ConditionTracer($dialect));
	}

	/**
	 * A `besloten` state reachable three ways, guarded once.
	 *
	 * @return array<string, mixed> The lifecycle annotation.
	 */
	private function annotation(): array {
		return [
			'field' => 'status',
			'initial' => 'open',
			'states' => [
				'besloten' => [
					'entry' => ['!!' => ['var' => 'object.besluit']],
				],
				'gesloten' => [
					'exit' => ['!!' => ['var' => 'object.heropeningsgrond']],
				],
			],
			'transitions' => [
				'besluiten' => ['from' => ['open'], 'to' => 'besloten'],
				'besluitenNaBezwaar' => ['from' => ['bezwaar'], 'to' => 'besloten'],
				'besluitenNaHerstel' => ['from' => ['herstel'], 'to' => 'besloten'],
			],
		];
	}

	/**
	 * Every declared path into `besloten` meets the same entry condition.
	 *
	 * @return void
	 */
	public function testOneRuleGuardsEveryPathIntoAState(): void {
		foreach (['open', 'bezwaar', 'herstel'] as $from) {
			$refusal = $this->evaluator->refusal(
				annotation: $this->annotation(),
				newData: ['status' => 'besloten'],
				from: $from,
				to: 'besloten'
			);

			$this->assertNotNull($refusal, sprintf('the move from "%s" was not refused', $from));
			$this->assertSame(StateConditionEvaluator::CODE_ENTRY, $refusal['code']);
			$this->assertSame('besloten', $refusal['state']);
			$this->assertStringContainsString('besluit', (string)$refusal['message']);
		}
	}

	/**
	 * @return void
	 */
	public function testTheMoveIsAllowedOnceTheConditionHolds(): void {
		$refusal = $this->evaluator->refusal(
			annotation: $this->annotation(),
			newData: ['status' => 'besloten', 'besluit' => 'toegekend'],
			from: 'open',
			to: 'besloten'
		);

		$this->assertNull($refusal);
	}

	/**
	 * @return void
	 */
	public function testAnExitConditionRefusesOnTheWayOut(): void {
		$refusal = $this->evaluator->refusal(
			annotation: $this->annotation(),
			newData: ['status' => 'open'],
			from: 'gesloten',
			to: 'open'
		);

		$this->assertNotNull($refusal);
		$this->assertSame(StateConditionEvaluator::CODE_EXIT, $refusal['code']);
		$this->assertSame('gesloten', $refusal['state']);
	}

	/**
	 * The refusal names the clause that failed, not just the whole condition.
	 *
	 * @return void
	 */
	public function testTheFailingClauseOfAnAndIsNamed(): void {
		$annotation = [
			'field' => 'status',
			'states' => [
				'besloten' => [
					'entry' => [
						'and' => [
							['!!' => ['var' => 'object.besluit']],
							['!!' => ['var' => 'object.motivering']],
						],
					],
				],
			],
		];

		$refusal = $this->evaluator->refusal(
			annotation: $annotation,
			newData: ['status' => 'besloten', 'besluit' => 'toegekend'],
			from: 'open',
			to: 'besloten'
		);

		$this->assertNotNull($refusal);
		$this->assertArrayHasKey('clause', $refusal);
		$this->assertStringContainsString('motivering', (string)$refusal['clause']);
		// The first clause held, so naming it would be the wrong answer even
		// though the whole condition is false.
		$this->assertStringNotContainsString('besluit"', (string)$refusal['clause']);
	}

	/**
	 * @return void
	 */
	public function testTheAuthorsOwnMessageIsUsedWhenDeclared(): void {
		$annotation = [
			'field' => 'status',
			'states' => [
				'besloten' => [
					'entry' => ['!!' => ['var' => 'object.besluit']],
					'entryMessage' => 'Leg eerst het besluit vast.',
				],
			],
		];

		$refusal = $this->evaluator->refusal(
			annotation: $annotation,
			newData: ['status' => 'besloten'],
			from: 'open',
			to: 'besloten'
		);

		$this->assertNotNull($refusal);
		$this->assertSame('Leg eerst het besluit vast.', $refusal['message']);
	}

	/**
	 * @return void
	 */
	public function testTheNestedConditionShapeIsAccepted(): void {
		$annotation = [
			'field' => 'status',
			'states' => [
				'besloten' => [
					'entry' => [
						'condition' => ['!!' => ['var' => 'object.besluit']],
						'message' => 'Besluit ontbreekt.',
					],
				],
			],
		];

		$refusal = $this->evaluator->refusal(
			annotation: $annotation,
			newData: ['status' => 'besloten'],
			from: 'open',
			to: 'besloten'
		);

		$this->assertNotNull($refusal);
		$this->assertSame('Besluit ontbreekt.', $refusal['message']);
	}

	/**
	 * @return void
	 */
	public function testASaveThatDoesNotChangeStateIsNeitherAnEntryNorAnExit(): void {
		$refusal = $this->evaluator->refusal(
			annotation: $this->annotation(),
			newData: ['status' => 'besloten'],
			from: 'besloten',
			to: 'besloten'
		);

		$this->assertNull($refusal);
	}

	/**
	 * @return void
	 */
	public function testADisabledStateBlockStopsGuardingEntirely(): void {
		$annotation = $this->annotation();
		$annotation['states']['besloten']['enabled'] = false;

		$refusal = $this->evaluator->refusal(
			annotation: $annotation,
			newData: ['status' => 'besloten'],
			from: 'open',
			to: 'besloten'
		);

		$this->assertNull($refusal);
	}
}
