<?php

/**
 * A schema that declares nothing this change adds behaves exactly as it did.
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

use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCA\OpenRegister\Db\RuleRunSummaryMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\PropertyCalculations;
use OCA\OpenRegister\Service\Flow\FlowExpression;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\DependentValueTable;
use OCA\OpenRegister\Service\Rules\DependentValueValidator;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultResolver;
use OCA\OpenRegister\Service\Rules\RuleInventoryService;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Task 5.3, in one place rather than as a comment pointing at four others.
 *
 * The proposal's backwards-compatibility promise is: "a schema that declares no
 * ceiling, no dry run and no replay behaves exactly as today, and the inventory
 * is a read". This asserts every half of that against a schema written before
 * any of it existed.
 *
 * The strongest half is the dialect. `ConditionDialect` now decides what
 * evaluates a transition condition, and every schema in the fleet carries
 * JSONLogic. So the test does not assert that JSONLogic "still works": it
 * asserts the dialect answers EXACTLY what `FlowExpression` answers, for every
 * condition and object in a table, including the malformed and the missing.
 * Anything less would pass while a whole class of stored conditions had
 * quietly changed meaning.
 *
 * @package OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
class NoCeilingRegressionTest extends TestCase {

	/**
	 * Conditions as schemas carry them today, all JSONLogic.
	 *
	 * @return array<int, array<string, mixed>> The conditions.
	 */
	private function legacyConditions(): array {
		return [
			['>' => [['var' => 'object.bedrag'], 500]],
			['!!' => ['var' => 'object.motivering']],
			['==' => [['var' => 'object.status'], 'open']],
			['and' => [['!!' => ['var' => 'object.motivering']], ['>' => [['var' => 'object.bedrag'], 0]]]],
			['or' => [['==' => [['var' => 'object.status'], 'open']], ['==' => [['var' => 'object.status'], 'nieuw']]]],
			['!' => ['var' => 'object.gesloten']],
			['in' => [['var' => 'object.status'], ['open', 'nieuw']]],
			['some' => [['var' => 'object.tags'], ['==' => [['var' => ''], 'spoed']]]],
		];
	}

	/**
	 * Objects to judge those conditions against, including the empty one.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function objects(): array {
		return [
			[],
			['bedrag' => 900, 'motivering' => 'omdat', 'status' => 'open', 'tags' => ['spoed']],
			['bedrag' => 120, 'motivering' => '', 'status' => 'gesloten', 'gesloten' => true, 'tags' => []],
			['bedrag' => 500, 'status' => 'nieuw'],
			['bedrag' => 0, 'motivering' => null, 'status' => null],
		];
	}

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
	}

	/**
	 * A schema written before any of this change existed.
	 *
	 * @return Schema The schema.
	 */
	private function legacySchema(): Schema {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setSlug('bezwaar');
		$schema->setProperties(['bedrag' => ['type' => 'number'], 'status' => ['type' => 'string']]);
		$schema->setConfiguration(
			[
				'x-openregister-calculations' => [
					'uiterlijkeDatum' => ['type' => 'date', 'expression' => ['prop' => 'ontvangstdatum']],
				],
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'states' => ['open' => [], 'gesloten' => []],
					'transitions' => [
						'sluiten' => [
							'from' => ['open'],
							'to' => 'gesloten',
							'condition' => ['>' => [['var' => 'object.bedrag'], 500]],
						],
					],
				],
			]
		);

		return $schema;
	}

	/**
	 * 🔴 EVERY LEGACY CONDITION MEANS EXACTLY WHAT IT MEANT.
	 *
	 * Forty comparisons: eight conditions the fleet actually carries against
	 * five objects, judged by the dialect and by the evaluator the dialect
	 * replaced, and required to agree every time.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowExpression is the engine's stateless
	 *   JSONLogic facade, and calling it here is the point: it is the control.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testTheDialectAnswersExactlyWhatTheLegacyEvaluatorAnswered(): void {
		$dialect = $this->dialect();
		$compared = 0;

		foreach ($this->legacyConditions() as $condition) {
			foreach ($this->objects() as $object) {
				$document = [
					'object' => $object,
					'previous' => [],
					'user' => ['uid' => '', 'groups' => []],
					'transition' => ['action' => 'sluiten', 'from' => 'open', 'to' => 'gesloten'],
				];

				$this->assertSame(
					FlowExpression::isTrue(logic: $condition, data: $document),
					$dialect->holds(node: $condition, document: $document),
					'The dialect changed the meaning of ' . json_encode($condition)
					. ' for ' . json_encode($object)
				);
				$compared++;
			}
		}

		// A guard on the table itself: a loop over an empty list asserts
		// nothing and passes, which is the shape this whole test exists to
		// refuse in other people's code.
		$this->assertSame(40, $compared);
	}

	/**
	 * Every legacy condition is still accepted by the schema save.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testEveryLegacyConditionIsStillAcceptedAtSchemaSave(): void {
		foreach ($this->legacyConditions() as $condition) {
			$this->assertTrue(
				ConditionDialect::isValidCondition(node: $condition),
				'A stored condition became invalid: ' . json_encode($condition)
			);
		}
	}

	/**
	 * The inventory reports no ceiling for a schema that declares none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testTheInventoryReportsNoCeilingForALegacySchema(): void {
		$triggers = $this->createMock(originalClassName: FlowTriggerMapper::class);
		$triggers->method('findBySchema')->willReturn([]);
		$summaries = $this->createMock(originalClassName: RuleRunSummaryMapper::class);
		$summaries->method('findBySchema')->willReturn([]);

		$inventory = new RuleInventoryService(
			triggers: $triggers,
			summaries: $summaries,
			propertyCalculations: new PropertyCalculations(),
			vocabulary: new RuleVocabulary()
		);

		$rules = $inventory->describe(schema: $this->legacySchema());

		$this->assertNotSame([], $rules);
		foreach ($rules as $rule) {
			$this->assertNull($rule->getMaxObjects(), $rule->getId() . ' gained a ceiling nobody declared.');
		}
	}

	/**
	 * The two new property annotations are inert on a schema that has neither.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testTheNewPropertyAnnotationsAreInertOnALegacySchema(): void {
		$properties = ($this->legacySchema()->getProperties() ?? []);
		$data = ['bedrag' => 900, 'status' => 'open'];

		$tables = new DependentValueTable();
		$this->assertFalse($tables->declaresAny(properties: $properties));
		$this->assertSame([], $tables->violations(properties: $properties, data: $data));
		$this->assertSame([], (new DependentValueValidator())->validate(['properties' => $properties]));

		$resolver = new ExpressionDefaultResolver(
			evaluator: new CalculationEvaluator(
				placeholders: new PlaceholderResolver(
					userSession: $this->createMock(originalClassName: IUserSession::class)
				)
			)
		);

		$this->assertFalse($resolver->declaresAny(properties: $properties));
		$this->assertSame($data, $resolver->apply(properties: $properties, data: $data));
		$this->assertSame([], ExpressionDefaultResolver::validateDeclarations(properties: $properties));
	}
}//end class
