<?php

/**
 * `holds()` is the one place a lifecycle rule is evaluated.
 *
 * A transition's `condition` and its `autoWhen` both come through this method,
 * against one document built by one builder, so the two fail-closed guards the
 * declarative-conditions link paid for are shared rather than rediscovered: a
 * value that is not a non-empty rule object never reaches FlowExpression, and
 * an expression that cannot be evaluated counts as not holding.
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

use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Rule evaluation shared by `condition` and `autoWhen`.
 */
class LifecycleConditionEvaluatorHoldsTest extends TestCase {

	private LoggerInterface&MockObject $logger;

	private IUserSession&MockObject $userSession;

	private LifecycleConditionEvaluator $evaluator;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar-1');
		$this->userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['behandelaars']);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('en');
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		$this->evaluator = new LifecycleConditionEvaluator(
			$this->userSession,
			$groupManager,
			$l10n,
			$this->logger
		);
	}//end setUp()

	/**
	 * Evaluate a rule against a fixed object and transition.
	 *
	 * @param mixed $rule The rule to evaluate.
	 * @param array<string, mixed> $object The object as stored.
	 * @param array<string, mixed> $previous The object before the write.
	 *
	 * @return bool Whether the rule holds.
	 */
	private function holds(mixed $rule, array $object = [], array $previous = []): bool {
		return $this->evaluator->holds(
			rule: $rule,
			newData: $object,
			oldData: $previous,
			action: 'beslissen',
			from: 'in-behandeling',
			to: 'besloten',
			schemaSlug: 'bezwaar',
			field: 'status'
		);
	}//end holds()

	/**
	 * @return void
	 */
	public function testAScalarRuleDoesNotHoldAndWarns(): void {
		// 🔴 The fail-open case FlowExpression cannot catch: a scalar is a
		// literal there, and a truthy literal would fire on every write.
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('not a JSONLogic rule object'));

		$this->assertFalse($this->holds(true));
	}//end testAScalarRuleDoesNotHoldAndWarns()

	/**
	 * @return void
	 */
	public function testAnEmptyRuleDoesNotHoldAndWarns(): void {
		$this->logger->expects($this->once())->method('warning');

		$this->assertFalse($this->holds([]));
	}//end testAnEmptyRuleDoesNotHoldAndWarns()

	/**
	 * @return void
	 */
	public function testAnUnevaluableRuleDoesNotHold(): void {
		// An unknown operator throws inside JSONLogic; isTrue() answers false,
		// so an unevaluable rule never moves an object.
		$this->assertFalse($this->holds(['nosuchoperator' => [1]]));
	}//end testAnUnevaluableRuleDoesNotHold()

	/**
	 * @return void
	 */
	public function testARuleThatIsFalseDoesNotHoldAndLogsNothing(): void {
		// A well-formed rule that does not hold is the common case on every
		// write. A line per write per candidate would drown the malformed case.
		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->never())->method('debug');

		$this->assertFalse($this->holds(['!!' => ['var' => 'object.motivering']], ['motivering' => '']));
	}//end testARuleThatIsFalseDoesNotHoldAndLogsNothing()

	/**
	 * @return void
	 */
	public function testARuleThatIsTrueHolds(): void {
		$this->assertTrue(
			$this->holds(['!!' => ['var' => 'object.motivering']], ['motivering' => 'Ongegrond.'])
		);
	}//end testARuleThatIsTrueHolds()

	/**
	 * @return void
	 */
	public function testTheDocumentCarriesTheFourKeys(): void {
		// `object`, `previous`, `user` and `transition`, and no others. Each
		// assertion reads one key through a rule, which is the only way the
		// document is observable from outside.
		$this->assertTrue($this->holds(['==' => [['var' => 'object.motivering'], 'ja']], ['motivering' => 'ja']));
		$this->assertTrue(
			$this->holds(['==' => [['var' => 'previous.status'], 'open']], [], ['status' => 'open'])
		);
		$this->assertTrue($this->holds(['==' => [['var' => 'user.uid'], 'behandelaar-1']]));
		$this->assertTrue($this->holds(['in' => ['behandelaars', ['var' => 'user.groups']]]));
		$this->assertTrue($this->holds(['==' => [['var' => 'transition.action'], 'beslissen']]));
		$this->assertTrue($this->holds(['==' => [['var' => 'transition.from'], 'in-behandeling']]));
		$this->assertTrue($this->holds(['==' => [['var' => 'transition.to'], 'besloten']]));
	}//end testTheDocumentCarriesTheFourKeys()

	/**
	 * @return void
	 */
	public function testAnAbsentKeyResolvesToNull(): void {
		$this->assertTrue($this->holds(['==' => [['var' => 'object.nosuchfield'], null]]));
		$this->assertTrue($this->holds(['==' => [['var' => 'previous.status'], null]]));
	}//end testAnAbsentKeyResolvesToNull()

	/**
	 * @return void
	 */
	public function testRefusalStillAnswersThroughHolds(): void {
		// refusal() is now a caller of holds(); a condition that holds lets the
		// transition through and one that does not refuses, unchanged.
		$spec = ['condition' => ['!!' => ['var' => 'object.motivering']]];

		$this->assertNull(
			$this->evaluator->refusal(
				spec: $spec,
				newData: ['motivering' => 'Ongegrond.'],
				oldData: [],
				action: 'beslissen',
				from: 'in-behandeling',
				to: 'besloten',
				schemaSlug: 'bezwaar',
				field: 'status'
			)
		);

		$refusal = $this->evaluator->refusal(
			spec: $spec,
			newData: ['motivering' => ''],
			oldData: [],
			action: 'beslissen',
			from: 'in-behandeling',
			to: 'besloten',
			schemaSlug: 'bezwaar',
			field: 'status'
		);
		$this->assertSame('lifecycle-condition-unmet', $refusal['code']);
	}//end testRefusalStillAnswersThroughHolds()
}//end class
