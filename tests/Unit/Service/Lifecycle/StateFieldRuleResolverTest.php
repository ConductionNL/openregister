<?php

/**
 * StateFieldRuleResolver tests.
 *
 * The resolver is the one reading of `x-openregister-lifecycle.states.<state>.fields`
 * that the render path, the save path and `@self.fieldRules` all share, so every
 * property the change promises is asserted here rather than three times over:
 *  - a rule without `groups` applies to everyone;
 *  - a rule with `groups` applies to members and to nobody else;
 *  - a conditional rule applies only when its condition holds, and the operand
 *    may be any declared property rather than the lifecycle field;
 *  - `enabled: false` — the switch RuleEnablementService writes — turns the
 *    whole state block off;
 *  - the resolved answer describes the object as it stands.
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
 * @spec openspec/changes/field-rules-by-state/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver
 */
class StateFieldRuleResolverTest extends TestCase {

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
	}

	/**
	 * Build a resolver whose caller sits in the given groups.
	 *
	 * @param array<int, string> $groups The caller's groups.
	 *
	 * @return StateFieldRuleResolver The resolver.
	 */
	private function resolverFor(array $groups): StateFieldRuleResolver {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);

		return new StateFieldRuleResolver(
			$this->userSession,
			$this->groupManager,
			new ConditionDialect(new CalculationEvaluator(new PlaceholderResolver($this->userSession)))
		);
	}

	/**
	 * The annotation the scenarios are written against.
	 *
	 * @return array<string, mixed> The lifecycle annotation.
	 */
	private function annotation(): array {
		return [
			'field' => 'status',
			'initial' => 'open',
			'states' => [
				'open' => [
					'fields' => [
						'required' => [
							['fields' => ['motivering'], 'when' => ['>' => [['var' => 'object.bedrag'], 50000]]],
						],
					],
				],
				'intake' => [
					'fields' => [
						'hidden' => [
							['fields' => ['internalNote'], 'groups' => ['frontdesk']],
						],
					],
				],
				'closed' => [
					'fields' => [
						'required' => [['fields' => ['outcome']]],
						'readOnly' => [['fields' => ['decision'], 'groups' => ['handlers']]],
					],
				],
			],
		];
	}

	/**
	 * @return void
	 */
	public function testARuleWithoutGroupsAppliesToEveryone(): void {
		$rules = $this->resolverFor([])->resolveFromAnnotation(
			annotation: $this->annotation(),
			data: ['status' => 'closed']
		);

		$this->assertSame(['outcome'], $rules->getRequired());
		$this->assertSame('closed', $rules->getState());
	}

	/**
	 * @return void
	 */
	public function testAGroupScopedRuleAppliesToAMember(): void {
		$rules = $this->resolverFor(['handlers'])->resolveFromAnnotation(
			annotation: $this->annotation(),
			data: ['status' => 'closed']
		);

		$this->assertSame(['decision'], $rules->getReadOnly());
	}

	/**
	 * @return void
	 */
	public function testAGroupScopedRuleSparesANonMember(): void {
		$rules = $this->resolverFor(['frontdesk'])->resolveFromAnnotation(
			annotation: $this->annotation(),
			data: ['status' => 'closed']
		);

		$this->assertSame([], $rules->getReadOnly());
		// The group-less rule in the same state still applies, which is what
		// separates "no rules resolved at all" from "this rule did not match".
		$this->assertSame(['outcome'], $rules->getRequired());
	}

	/**
	 * @return void
	 */
	public function testAFieldBecomesRequiredBecauseOfAValue(): void {
		$rules = $this->resolverFor([])->resolveFromAnnotation(
			annotation: $this->annotation(),
			data: ['status' => 'open', 'bedrag' => 60000]
		);

		$this->assertSame(['motivering'], $rules->getRequired());
	}

	/**
	 * @return void
	 */
	public function testTheSameFieldIsNotRequiredBelowTheThreshold(): void {
		$rules = $this->resolverFor([])->resolveFromAnnotation(
			annotation: $this->annotation(),
			data: ['status' => 'open', 'bedrag' => 400]
		);

		$this->assertSame([], $rules->getRequired());
		$this->assertNotContains('motivering', $rules->jsonSerialize()['required']);
	}

	/**
	 * A condition may read any declared property, not only the lifecycle field.
	 *
	 * `bedrag` above is exactly such a property: nothing in the lifecycle
	 * declares it, and an extending form is the usual way it arrives. This
	 * asserts the other half — that the operand is read from the object's own
	 * data and not from a fixed set of built-in scalars.
	 *
	 * @return void
	 */
	public function testAConditionReadsAPropertyTheLifecycleDoesNotDeclare(): void {
		$annotation = [
			'field' => 'status',
			'states' => [
				'open' => [
					'fields' => [
						'readOnly' => [
							[
								'fields' => ['iban'],
								'when' => ['==' => [['var' => 'object.betaalwijze'], 'automatischeIncasso']],
							],
						],
					],
				],
			],
		];

		$resolver = $this->resolverFor([]);

		$applies = $resolver->resolveFromAnnotation(
			annotation: $annotation,
			data: ['status' => 'open', 'betaalwijze' => 'automatischeIncasso']
		);
		$this->assertSame(['iban'], $applies->getReadOnly());

		$doesNot = $resolver->resolveFromAnnotation(
			annotation: $annotation,
			data: ['status' => 'open', 'betaalwijze' => 'overboeking']
		);
		$this->assertSame([], $doesNot->getReadOnly());
	}

	/**
	 * @return void
	 */
	public function testADisabledStateBlockResolvesToNothing(): void {
		$annotation = $this->annotation();
		$annotation['states']['closed']['enabled'] = false;

		$rules = $this->resolverFor([])->resolveFromAnnotation(
			annotation: $annotation,
			data: ['status' => 'closed']
		);

		$this->assertTrue($rules->isEmpty());
	}

	/**
	 * @return void
	 */
	public function testABlockConditionGatesTheWholeBlock(): void {
		$annotation = $this->annotation();
		$annotation['states']['closed']['condition'] = ['==' => [['var' => 'object.soort'], 'bezwaar']];

		$resolver = $this->resolverFor([]);

		$off = $resolver->resolveFromAnnotation(
			annotation: $annotation,
			data: ['status' => 'closed', 'soort' => 'melding']
		);
		$this->assertTrue($off->isEmpty());

		$on = $resolver->resolveFromAnnotation(
			annotation: $annotation,
			data: ['status' => 'closed', 'soort' => 'bezwaar']
		);
		$this->assertSame(['outcome'], $on->getRequired());
	}

	/**
	 * @return void
	 */
	public function testASchemaWithoutAStateBlockResolvesToNothing(): void {
		$rules = $this->resolverFor([])->resolveFromAnnotation(
			annotation: ['field' => 'status', 'initial' => 'open'],
			data: ['status' => 'open']
		);

		$this->assertTrue($rules->isEmpty());
		$this->assertSame(['state' => 'open', 'hidden' => [], 'readOnly' => [], 'required' => []], $rules->jsonSerialize());
	}

	/**
	 * @return void
	 */
	public function testAnObjectWithNoStateValueResolvesToNothing(): void {
		$rules = $this->resolverFor([])->resolveFromAnnotation(
			annotation: $this->annotation(),
			data: ['bedrag' => 60000]
		);

		$this->assertTrue($rules->isEmpty());
		$this->assertNull($rules->getState());
	}

	/**
	 * @return void
	 */
	public function testTheResultingStateCanBeAskedForExplicitly(): void {
		$rules = $this->resolverFor([])->resolveFromAnnotation(
			annotation: $this->annotation(),
			data: ['status' => 'open'],
			state: 'closed'
		);

		$this->assertSame('closed', $rules->getState());
		$this->assertSame(['outcome'], $rules->getRequired());
	}

	/**
	 * @return void
	 */
	public function testHiddenRulesAreScopedToTheirGroup(): void {
		$frontdesk = $this->resolverFor(['frontdesk'])->resolveFromAnnotation(
			annotation: $this->annotation(),
			data: ['status' => 'intake']
		);
		$this->assertSame(['internalNote'], $frontdesk->getHidden());
		$this->assertTrue($frontdesk->hides('internalNote'));
	}

	/**
	 * @return void
	 */
	public function testAHandlerKeepsTheFieldTheFrontDeskLoses(): void {
		$handler = $this->resolverFor(['handlers'])->resolveFromAnnotation(
			annotation: $this->annotation(),
			data: ['status' => 'intake']
		);

		$this->assertSame([], $handler->getHidden());
		$this->assertFalse($handler->hides('internalNote'));
	}
}
