<?php

/**
 * The JSON AST as a condition dialect: the save path, the schema save and the
 * two dialects agreeing about the same rule.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Task 4.2: the AST is accepted for a condition operand, beside JSONLogic.
 *
 * The point of the test is not that the AST evaluates. That is
 * CalculationEvaluator's own suite. The point is that the SAVE PATH and the
 * SCHEMA SAVE reach the same conclusion about the same rule, because a dry run
 * that understood a dialect the save path does not would answer "it fires"
 * about a rule that refuses every transition in production.
 *
 * @package OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
 */
class ConditionDialectOnSavePathTest extends TestCase {

	/**
	 * The same rule, in both dialects: "bedrag is above 500".
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const ABOVE_FIVE_HUNDRED = [
		'ast' => ['gt' => [['prop' => 'object.bedrag'], 500]],
		'jsonlogic' => ['>' => [['var' => 'object.bedrag'], 500]],
	];

	/**
	 * The dialect over the real AST evaluator.
	 *
	 * @return ConditionDialect The dialect.
	 */
	private function dialect(): ConditionDialect {
		return new ConditionDialect(
			ast: new CalculationEvaluator(
				placeholders: new PlaceholderResolver(
					userSession: $this->createMock(originalClassName: IUserSession::class)
				)
			)
		);

	}//end dialect()

	/**
	 * The condition evaluator as the save path builds it.
	 *
	 * @return LifecycleConditionEvaluator The evaluator.
	 */
	private function evaluator(): LifecycleConditionEvaluator {
		return new LifecycleConditionEvaluator(
			$this->createMock(originalClassName: IUserSession::class),
			$this->createMock(originalClassName: IGroupManager::class),
			$this->createMock(originalClassName: IL10N::class),
			$this->createMock(originalClassName: LoggerInterface::class),
			$this->dialect()
		);

	}//end evaluator()

	/**
	 * Ask the save path whether a rule holds for an object.
	 *
	 * @param mixed $rule The condition, in either dialect.
	 * @param array<string, mixed> $object The object as it would be saved.
	 *
	 * @return bool Whether the transition may proceed.
	 */
	private function holds(mixed $rule, array $object): bool {
		return $this->evaluator()->holds(
			rule: $rule,
			newData: $object,
			oldData: [],
			action: 'beslissen',
			from: 'in-behandeling',
			to: 'besloten',
			schemaSlug: 'bezwaar',
			field: 'status'
		);

	}//end holds()

	/**
	 * A schema carrying one transition with the given condition keys.
	 *
	 * @param array<string, mixed> $spec The transition spec.
	 *
	 * @return array<string, mixed> The schema shape the validator reads.
	 */
	private function schemaWith(array $spec): array {
		return [
			'properties' => [
				'status' => ['type' => 'string', 'enum' => ['in-behandeling', 'besloten']],
				'bedrag' => ['type' => 'number'],
			],
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'initial' => 'in-behandeling',
				'states' => ['in-behandeling' => [], 'besloten' => []],
				'transitions' => [
					'beslissen' => array_merge(['from' => 'in-behandeling', 'to' => 'besloten'], $spec),
				],
			],
		];

	}//end schemaWith()

	/**
	 * The error codes a schema save would produce.
	 *
	 * @param array<string, mixed> $spec The transition spec.
	 *
	 * @return array<int, string> The codes.
	 */
	private function codesFor(array $spec): array {
		$errors = (new LifecycleAnnotationValidator())->validate($this->schemaWith(spec: $spec));

		return array_map(static fn (array $error): string => (string)$error['code'], $errors);

	}//end codesFor()

	/**
	 * 🔴 THE CONTROL FOR EVERY assertNotContains BELOW.
	 *
	 * The lifecycle validator stops at the first structural fault, so a
	 * fixture missing one required key produces that one error and NO
	 * condition error at all, and every "the condition was accepted"
	 * assertion passes without the condition having been looked at. This
	 * asserts the fixture with no condition on it is clean, which is what
	 * makes the negations below mean something.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testTheFixtureItselfIsAValidSchema(): void {
		$this->assertSame([], $this->codesFor([]));

	}//end testTheFixtureItselfIsAValidSchema()

	/**
	 * The save path evaluates an AST condition, in both directions.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testTheSavePathEvaluatesAnAstCondition(): void {
		$this->assertTrue($this->holds(self::ABOVE_FIVE_HUNDRED['ast'], ['bedrag' => 900]));
		$this->assertFalse($this->holds(self::ABOVE_FIVE_HUNDRED['ast'], ['bedrag' => 120]));

	}//end testTheSavePathEvaluatesAnAstCondition()

	/**
	 * The two dialects answer the same about the same rule.
	 *
	 * 🔴 THIS IS THE ASSERTION 4.2 EXISTS FOR. A rule an administrator
	 * rewrites from JSONLogic into the AST must keep refusing exactly what it
	 * refused, or the migration D-7 recommends silently reopens a gate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testBothDialectsAnswerTheSameAboutTheSameRule(): void {
		foreach ([['bedrag' => 900], ['bedrag' => 120], ['bedrag' => 500], []] as $object) {
			$this->assertSame(
				$this->holds(self::ABOVE_FIVE_HUNDRED['jsonlogic'], $object),
				$this->holds(self::ABOVE_FIVE_HUNDRED['ast'], $object),
				'The dialects disagree about ' . json_encode($object)
			);
		}

	}//end testBothDialectsAnswerTheSameAboutTheSameRule()

	/**
	 * A schema save accepts an AST condition and an AST autoWhen.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testASchemaSaveAcceptsAnAstConditionAndAnAstAutoWhen(): void {
		$this->assertNotContains(
			'lifecycle-condition-malformed',
			$this->codesFor(['condition' => self::ABOVE_FIVE_HUNDRED['ast']])
		);

		$this->assertNotContains(
			'lifecycle-autowhen-malformed',
			$this->codesFor(['autoWhen' => self::ABOVE_FIVE_HUNDRED['ast']])
		);

	}//end testASchemaSaveAcceptsAnAstConditionAndAnAstAutoWhen()

	/**
	 * A schema save still accepts the legacy JSONLogic condition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testASchemaSaveStillAcceptsTheLegacyDialect(): void {
		$this->assertNotContains(
			'lifecycle-condition-malformed',
			$this->codesFor(['condition' => self::ABOVE_FIVE_HUNDRED['jsonlogic']])
		);

	}//end testASchemaSaveStillAcceptsTheLegacyDialect()

	/**
	 * An operator no engine holds is refused at schema save, not at run time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testAnUnknownOperatorIsRefusedAtSchemaSave(): void {
		$this->assertContains(
			'lifecycle-condition-malformed',
			$this->codesFor(['condition' => ['isVerySure' => [['prop' => 'object.bedrag']]]])
		);

	}//end testAnUnknownOperatorIsRefusedAtSchemaSave()

	/**
	 * A nested unknown operator is refused too, not only a top-level one.
	 *
	 * The walk is the part that can rot: a shape check that only reads the
	 * outermost key accepts a tree with anything inside it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testANestedUnknownOperatorIsRefusedToo(): void {
		$this->assertContains(
			'lifecycle-condition-malformed',
			$this->codesFor(
				[
					'condition' => ['gt' => [['isVerySure' => [['prop' => 'object.bedrag']]], 500]],
				]
			)
		);

	}//end testANestedUnknownOperatorIsRefusedToo()

	/**
	 * A literal that happens to be a map is not walked as an expression.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testALiteralMapIsNotWalkedAsAnExpression(): void {
		$this->assertTrue(
			ConditionDialect::isWellFormedAst(node: ['lit' => ['zaaktype' => 'bezwaar']])
		);

	}//end testALiteralMapIsNotWalkedAsAnExpression()
}//end class
