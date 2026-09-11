<?php

/**
 * Which automatic transition may fire, and the three ways the answer is none.
 *
 * The selector owns selection and nothing else: whether a rule holds is
 * `LifecycleConditionEvaluator::holds()`'s answer, and one of the tests below
 * hands the selector a double that answers without evaluating anything, so a
 * second JSONLogic path inside the selector would show up as a test that
 * cannot be satisfied.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\AutoTransitionSelector;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Candidate selection for automatic transitions.
 */
class AutoTransitionSelectorTest extends TestCase {

	private const HOLDS = ['!!' => ['var' => 'object.motivering']];

	private LoggerInterface&MockObject $logger;

	private AutoTransitionSelector $selector;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->selector = new AutoTransitionSelector($this->realEvaluator(), $this->logger);
	}//end setUp()

	/**
	 * A real evaluator wired to mocked Nextcloud collaborators.
	 *
	 * @return LifecycleConditionEvaluator
	 */
	private function realEvaluator(): LifecycleConditionEvaluator {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn([]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('en');

		return new LifecycleConditionEvaluator(
			$this->createMock(IUserSession::class),
			$groupManager,
			$l10n,
			$this->createMock(LoggerInterface::class)
		);
	}//end realEvaluator()

	/**
	 * An annotation with the given transitions, in declaration order.
	 *
	 * @param array<string, mixed> $transitions The transition map.
	 *
	 * @return array<string, mixed>
	 */
	private function annotation(array $transitions): array {
		return ['field' => 'status', 'initial' => 'ontvangen', 'transitions' => $transitions];
	}//end annotation()

	/**
	 * @return void
	 */
	public function testTheOneHoldingTransitionIsSelected(): void {
		$candidate = $this->selector->select(
			annotation: $this->annotation(
				['beslissen' => ['from' => ['in-behandeling'], 'to' => 'besloten', 'autoWhen' => self::HOLDS]]
			),
			objectData: ['status' => 'in-behandeling', 'motivering' => 'Ongegrond.'],
			previous: ['status' => 'in-behandeling'],
			schemaSlug: 'bezwaar'
		);

		$this->assertNotNull($candidate);
		$this->assertSame('beslissen', $candidate->action);
		$this->assertSame('in-behandeling', $candidate->from);
		$this->assertSame('besloten', $candidate->to);
		$this->assertSame('sync', $candidate->mode);
	}//end testTheOneHoldingTransitionIsSelected()

	/**
	 * @return void
	 */
	public function testARuleThatDoesNotHoldSelectsNothing(): void {
		$this->assertNull(
			$this->selector->select(
				annotation: $this->annotation(
					['beslissen' => ['from' => ['in-behandeling'], 'to' => 'besloten', 'autoWhen' => self::HOLDS]]
				),
				objectData: ['status' => 'in-behandeling', 'motivering' => ''],
				previous: [],
				schemaSlug: 'bezwaar'
			)
		);
	}//end testARuleThatDoesNotHoldSelectsNothing()

	/**
	 * @return void
	 */
	public function testATransitionWithoutAutoWhenIsNeverSelected(): void {
		$this->assertNull(
			$this->selector->select(
				annotation: $this->annotation(
					['beslissen' => ['from' => ['in-behandeling'], 'to' => 'besloten']]
				),
				objectData: ['status' => 'in-behandeling', 'motivering' => 'Ongegrond.'],
				previous: [],
				schemaSlug: 'bezwaar'
			)
		);
	}//end testATransitionWithoutAutoWhenIsNeverSelected()

	/**
	 * @return void
	 */
	public function testAMoveToTheCurrentValueIsNeverSelected(): void {
		$this->assertNull(
			$this->selector->select(
				annotation: $this->annotation(
					[
						'herbevestigen' => [
							'from' => ['ontvangen', 'in-behandeling'],
							'to' => 'in-behandeling',
							'autoWhen' => self::HOLDS,
						],
					]
				),
				objectData: ['status' => 'in-behandeling', 'motivering' => 'Ongegrond.'],
				previous: [],
				schemaSlug: 'bezwaar'
			)
		);
	}//end testAMoveToTheCurrentValueIsNeverSelected()

	/**
	 * @return void
	 */
	public function testTwoHoldingRulesSelectNothingAndNameBoth(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('More than one automatic transition holds'),
				$this->callback(
					static fn (array $context): bool => $context['candidates'] === ['toewijzen', 'afwijzen']
				)
			);

		$this->assertNull(
			$this->selector->select(
				annotation: $this->annotation(
					[
						'toewijzen' => ['from' => ['ontvangen'], 'to' => 'in-behandeling', 'autoWhen' => self::HOLDS],
						'afwijzen' => ['from' => ['ontvangen'], 'to' => 'afgewezen', 'autoWhen' => self::HOLDS],
					]
				),
				objectData: ['status' => 'ontvangen', 'motivering' => 'Ongegrond.'],
				previous: [],
				schemaSlug: 'bezwaar'
			)
		);
	}//end testTwoHoldingRulesSelectNothingAndNameBoth()

	/**
	 * @return void
	 */
	public function testOneHoldingBesideOneThatDoesNotIsSelected(): void {
		$candidate = $this->selector->select(
			annotation: $this->annotation(
				[
					'toewijzen' => [
						'from' => ['ontvangen'],
						'to' => 'in-behandeling',
						'autoWhen' => ['!!' => ['var' => 'object.behandelaar']],
					],
					'afwijzen' => ['from' => ['ontvangen'], 'to' => 'afgewezen', 'autoWhen' => self::HOLDS],
				]
			),
			objectData: ['status' => 'ontvangen', 'motivering' => 'Ongegrond.'],
			previous: [],
			schemaSlug: 'bezwaar'
		);

		$this->assertNotNull($candidate);
		$this->assertSame('afwijzen', $candidate->action);
	}//end testOneHoldingBesideOneThatDoesNotIsSelected()

	/**
	 * @return void
	 */
	public function testAShadowedTransitionIsNotSelectedAndBothAreNamed(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('earlier-declared transition'),
				$this->callback(
					static fn (array $context): bool => $context['action'] === 'toewijzen'
						&& $context['shadowedBy'] === 'starten'
				)
			);

		$this->assertNull(
			$this->selector->select(
				annotation: $this->annotation(
					[
						'starten' => ['from' => ['ontvangen'], 'to' => 'in-behandeling'],
						'toewijzen' => ['from' => ['ontvangen'], 'to' => 'in-behandeling', 'autoWhen' => self::HOLDS],
					]
				),
				objectData: ['status' => 'ontvangen', 'motivering' => 'Ongegrond.'],
				previous: [],
				schemaSlug: 'bezwaar'
			)
		);
	}//end testAShadowedTransitionIsNotSelectedAndBothAreNamed()

	/**
	 * @return void
	 */
	public function testALaterTwinDoesNotShadowTheCandidate(): void {
		// Shadowing is about an EARLIER declaration only: the save path takes
		// the first match, so a twin declared after the candidate cannot win.
		$candidate = $this->selector->select(
			annotation: $this->annotation(
				[
					'toewijzen' => ['from' => ['ontvangen'], 'to' => 'in-behandeling', 'autoWhen' => self::HOLDS],
					'starten' => ['from' => ['ontvangen'], 'to' => 'in-behandeling'],
				]
			),
			objectData: ['status' => 'ontvangen', 'motivering' => 'Ongegrond.'],
			previous: [],
			schemaSlug: 'bezwaar'
		);

		$this->assertNotNull($candidate);
		$this->assertSame('toewijzen', $candidate->action);
	}//end testALaterTwinDoesNotShadowTheCandidate()

	/**
	 * @return void
	 */
	public function testAnUnrecognisedExecutionModeSelectsNothingAndWarns(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('unrecognised executionMode'),
				$this->callback(static fn (array $context): bool => $context['action'] === 'beslissen')
			);

		$this->assertNull(
			$this->selector->select(
				annotation: $this->annotation(
					[
						'beslissen' => [
							'from' => ['in-behandeling'],
							'to' => 'besloten',
							'autoWhen' => self::HOLDS,
							'executionMode' => 'background',
						],
					]
				),
				objectData: ['status' => 'in-behandeling', 'motivering' => 'Ongegrond.'],
				previous: [],
				schemaSlug: 'bezwaar'
			)
		);
	}//end testAnUnrecognisedExecutionModeSelectsNothingAndWarns()

	/**
	 * @return void
	 */
	public function testAnAsyncModeIsCarriedOnTheCandidate(): void {
		$candidate = $this->selector->select(
			annotation: $this->annotation(
				[
					'beslissen' => [
						'from' => ['in-behandeling'],
						'to' => 'besloten',
						'autoWhen' => self::HOLDS,
						'executionMode' => 'async',
					],
				]
			),
			objectData: ['status' => 'in-behandeling', 'motivering' => 'Ongegrond.'],
			previous: [],
			schemaSlug: 'bezwaar'
		);

		$this->assertNotNull($candidate);
		$this->assertSame('async', $candidate->mode);
	}//end testAnAsyncModeIsCarriedOnTheCandidate()

	/**
	 * @return void
	 */
	public function testAStoredScalarAutoWhenSelectsNothing(): void {
		// The runtime does not trust save-time validation. A scalar written by
		// a path that skipped the mapper counts as not holding.
		$this->assertNull(
			$this->selector->select(
				annotation: $this->annotation(
					['beslissen' => ['from' => ['in-behandeling'], 'to' => 'besloten', 'autoWhen' => true]]
				),
				objectData: ['status' => 'in-behandeling'],
				previous: [],
				schemaSlug: 'bezwaar'
			)
		);
	}//end testAStoredScalarAutoWhenSelectsNothing()

	/**
	 * @return void
	 */
	public function testTheSelectorEvaluatesNoJsonLogicItself(): void {
		// 🔴 THE ONE-EVALUATION-PATH ASSERTION. The double answers `holds()`
		// without evaluating anything, and its rule is a string the JSONLogic
		// engine would refuse. A selector doing its own truthiness test would
		// either fire on the string or refuse the double's answer; both show up
		// here rather than as a quiet second code path.
		$evaluator = new class ($this->createMock(IUserSession::class), $this->createMock(IGroupManager::class), $this->createMock(IL10N::class), $this->createMock(LoggerInterface::class)) extends LifecycleConditionEvaluator {
			/**
			 * Rules this double was asked about.
			 *
			 * @var array<int, mixed>
			 */
			public array $asked = [];

			/**
			 * Answer true for every rule, without evaluating it.
			 *
			 * @param mixed $rule The rule.
			 * @param array<string, mixed> $newData The object.
			 * @param array<string, mixed> $oldData The previous object.
			 * @param string $action The transition name.
			 * @param string $from The state being left.
			 * @param string $to The state being entered.
			 * @param string $schemaSlug The schema slug.
			 * @param string $field The lifecycle field.
			 *
			 * @return bool Always true.
			 */
			public function holds(
				mixed $rule,
				array $newData,
				array $oldData,
				string $action,
				string $from,
				string $to,
				string $schemaSlug,
				string $field,
			): bool {
				$this->asked[] = $rule;
				return true;
			}
		};

		$selector = new AutoTransitionSelector($evaluator, $this->logger);
		$candidate = $selector->select(
			annotation: $this->annotation(
				[
					'beslissen' => [
						'from' => ['in-behandeling'],
						'to' => 'besloten',
						'autoWhen' => 'not a rule object at all',
					],
				]
			),
			objectData: ['status' => 'in-behandeling'],
			previous: [],
			schemaSlug: 'bezwaar'
		);

		$this->assertNotNull($candidate);
		$this->assertSame('beslissen', $candidate->action);
		$this->assertSame(['not a rule object at all'], $evaluator->asked);
	}//end testTheSelectorEvaluatesNoJsonLogicItself()

	/**
	 * @return void
	 */
	public function testDeclaresAutoWhenIsAShapeReadOnly(): void {
		$this->assertFalse(
			$this->selector->declaresAutoWhen(
				$this->annotation(['beslissen' => ['from' => ['open'], 'to' => 'besloten']])
			)
		);
		$this->assertTrue(
			$this->selector->declaresAutoWhen(
				$this->annotation(
					['beslissen' => ['from' => ['open'], 'to' => 'besloten', 'autoWhen' => self::HOLDS]]
				)
			)
		);
		$this->assertFalse($this->selector->declaresAutoWhen([]));
	}//end testDeclaresAutoWhenIsAShapeReadOnly()
}//end class
