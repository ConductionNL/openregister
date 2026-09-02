<?php

/**
 * Tests for the decision-table node.
 *
 * Two things carry the weight. First, the dossiq fixture: a table translated
 * from dossiq's real LHS enforcement matrix, driven through the
 * `inputMapping`/`outputMapping` vocabulary of dossiq's
 * `EvaluateDecisionHandler` — if this node cannot express that table, the
 * migration strands. Second, the refusals: a rule step that silently ignored
 * a mapping, wildcarded a missing input, or wrote half a default row would
 * report a completed decision that never happened.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\DecisionTableNode
 * @uses \OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator
 * @uses \OCA\OpenRegister\Service\Dmn\DecisionTableValidator
 * @uses \OCA\OpenRegister\Service\Dmn\UnaryTestEvaluator
 * @uses \OCA\OpenRegister\Service\Dmn\DecisionEvaluationException
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueTemplate
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Dmn\DecisionEvaluationException;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use OCA\OpenRegister\Service\Dmn\DecisionTableValidator;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\DecisionTableNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * DecisionTableNode behaviour.
 */
class DecisionTableNodeTest extends TestCase {

	/**
	 * The node under test.
	 *
	 * @var DecisionTableNode
	 */
	private DecisionTableNode $node;

	/**
	 * Build the node. The evaluator and validator are REAL, deliberately: a
	 * mocked evaluator here would be the fake-agrees-with-the-caller shape,
	 * and the whole point of the fixture is that the shared engine itself
	 * decides dossiq's table.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/icon.svg');

		$this->node = new DecisionTableNode(
			engine: new DecisionTableEvaluator(),
			validator: new DecisionTableValidator(),
			l10n: $l10n,
			urls: $urls
		);

	}//end setUp()

	/**
	 * dossiq's LHS enforcement matrix, in the shape
	 * `LhsMatrixDecisionTableMigrator::tableFor()` produces: three string
	 * inputs, one output, UNIQUE, one rule per matrix cell.
	 *
	 * @return array<string, mixed> The table.
	 */
	private function lhsTable(): array {
		return [
			'name' => 'LHS',
			'key' => 'lhs-matrix-1',
			'hitPolicy' => 'UNIQUE',
			'inputs' => [
				['name' => 'severity', 'type' => 'string'],
				['name' => 'behaviour', 'type' => 'string'],
				['name' => 'actorType', 'type' => 'string'],
			],
			'outputs' => [['name' => 'intervention', 'type' => 'string']],
			'rules' => [
				[
					'id' => 'ernstig:opzettelijk:bedrijf',
					'inputEntries' => ['ernstig', 'opzettelijk', 'bedrijf'],
					'outputEntries' => ['bestuurlijke boete'],
				],
				[
					'id' => 'ernstig:onverschillig:bedrijf',
					'inputEntries' => ['ernstig', 'onverschillig', 'bedrijf'],
					'outputEntries' => ['last onder dwangsom'],
				],
				[
					'id' => 'licht:goedwillend:burger',
					'inputEntries' => ['licht', 'goedwillend', 'burger'],
					'outputEntries' => ['waarschuwing'],
				],
			],
		];

	}//end lhsTable()

	/**
	 * A one-input, one-output table for the policy tests.
	 *
	 * @param string $hitPolicy The hit policy.
	 * @param array<int, array<string, mixed>> $rules The rules.
	 *
	 * @return array<string, mixed> The table.
	 */
	private function table(string $hitPolicy, array $rules): array {
		return [
			'hitPolicy' => $hitPolicy,
			'inputs' => [['name' => 'severity', 'type' => 'string']],
			'outputs' => [['name' => 'intervention', 'type' => 'string']],
			'rules' => $rules,
		];

	}//end table()

	/**
	 * One flow item.
	 *
	 * @param array<string, mixed> $json The record.
	 *
	 * @return array<string, mixed> The item.
	 */
	private function item(array $json): array {
		return FlowItems::item(json: $json);

	}//end item()

	/**
	 * The dossiq proof: the real LHS table, driven through dossiq's own
	 * mapping vocabulary, decided by the shared engine inside the node.
	 *
	 * @return void
	 */
	public function testDossiqLhsTableDecidesAnItem(): void {
		$config = [
			'table' => $this->lhsTable(),
			'inputMapping' => [
				'severity' => 'case.severity',
				'behaviour' => 'case.behaviour',
				'actorType' => 'case.actorType',
			],
			'outputMapping' => ['intervention' => 'advies.maatregel'],
			'resultKey' => 'advies.evaluatie',
		];

		$out = $this->node->execute(
			items: [$this->item(['case' => ['severity' => 'ernstig', 'behaviour' => 'opzettelijk', 'actorType' => 'bedrijf']])],
			config: $config,
			context: []
		);

		$this->assertCount(1, $out);
		$json = $out[0][FlowItems::JSON];
		$this->assertSame('bestuurlijke boete', $json['advies']['maatregel']);
		$this->assertSame(['ernstig:opzettelijk:bedrijf'], $json['advies']['evaluatie']['matchedRuleIds']);
		$this->assertSame('UNIQUE', $json['advies']['evaluatie']['hitPolicy']);
		$this->assertFalse($json['advies']['evaluatie']['defaulted']);
		$this->assertSame('lhs-matrix-1', $json['advies']['evaluatie']['tableKey']);

	}//end testDossiqLhsTableDecidesAnItem()

	/**
	 * Without mappings, every input reads and every output writes the field
	 * carrying its own name — dossiq's same-name default, kept verbatim.
	 *
	 * @return void
	 */
	public function testSameNameDefaultsMapInputsAndOutputs(): void {
		$out = $this->node->execute(
			items: [$this->item(['severity' => 'licht', 'behaviour' => 'goedwillend', 'actorType' => 'burger'])],
			config: ['table' => $this->lhsTable()],
			context: []
		);

		$this->assertSame('waarschuwing', $out[0][FlowItems::JSON]['intervention']);

	}//end testSameNameDefaultsMapInputsAndOutputs()

	/**
	 * The typed unary grammar decides typed item fields: a number read from
	 * the item stays a number on its way into a range cell.
	 *
	 * @return void
	 */
	public function testTypedExpressionsDecideTypedInputs(): void {
		$table = [
			'hitPolicy' => 'FIRST',
			'inputs' => [['name' => 'leeftijd', 'type' => 'number']],
			'outputs' => [['name' => 'categorie', 'type' => 'string']],
			'rules' => [
				['id' => 'jong', 'inputEntries' => ['< 18'], 'outputEntries' => ['minderjarig']],
				['id' => 'werk', 'inputEntries' => ['[18..67)'], 'outputEntries' => ['volwassen']],
				['id' => 'oud', 'inputEntries' => ['>= 67'], 'outputEntries' => ['gepensioneerd']],
			],
		];

		$out = $this->node->execute(
			items: [$this->item(['leeftijd' => 42])],
			config: ['table' => $table],
			context: []
		);

		$this->assertSame('volwassen', $out[0][FlowItems::JSON]['categorie']);

	}//end testTypedExpressionsDecideTypedInputs()

	/**
	 * UNIQUE with two matching rules is the evaluator's refusal, passed
	 * through untouched so the step's onError policy sees the real fault.
	 *
	 * @return void
	 */
	public function testUniqueViolationFailsTheStep(): void {
		$table = $this->table(
			'UNIQUE',
			[
				['id' => 'r1', 'inputEntries' => ['-'], 'outputEntries' => ['boete']],
				['id' => 'r2', 'inputEntries' => ['-'], 'outputEntries' => ['dwangsom']],
			]
		);

		$this->expectException(DecisionEvaluationException::class);
		$this->expectExceptionMessage('hit_policy_violation');

		$this->node->execute(items: [$this->item(['severity' => 'x'])], config: ['table' => $table], context: []);

	}//end testUniqueViolationFailsTheStep()

	/**
	 * FIRST takes declaration order.
	 *
	 * @return void
	 */
	public function testFirstTakesDeclarationOrder(): void {
		$table = $this->table(
			'FIRST',
			[
				['id' => 'r1', 'inputEntries' => ['-'], 'outputEntries' => ['eerste']],
				['id' => 'r2', 'inputEntries' => ['-'], 'outputEntries' => ['tweede']],
			]
		);

		$out = $this->node->execute(items: [$this->item(['severity' => 'x'])], config: ['table' => $table], context: []);

		$this->assertSame('eerste', $out[0][FlowItems::JSON]['intervention']);

	}//end testFirstTakesDeclarationOrder()

	/**
	 * PRIORITY takes the highest priority; an equal priority leaves the
	 * earlier rule in place, so the outcome is deterministic.
	 *
	 * @return void
	 */
	public function testPriorityTakesHighestAndTiesBreakByOrder(): void {
		$table = $this->table(
			'PRIORITY',
			[
				['id' => 'laag', 'inputEntries' => ['-'], 'outputEntries' => ['laag'], 'priority' => 1],
				['id' => 'hoog', 'inputEntries' => ['-'], 'outputEntries' => ['hoog'], 'priority' => 5],
				['id' => 'gelijk', 'inputEntries' => ['-'], 'outputEntries' => ['gelijk'], 'priority' => 5],
			]
		);

		$out = $this->node->execute(items: [$this->item(['severity' => 'x'])], config: ['table' => $table], context: []);

		$this->assertSame('hoog', $out[0][FlowItems::JSON]['intervention']);

	}//end testPriorityTakesHighestAndTiesBreakByOrder()

	/**
	 * ANY passes when every matching rule agrees and fails when they differ:
	 * a disagreement is a fault in the table, not a choice to make silently.
	 *
	 * @return void
	 */
	public function testAnyAgreesOrFails(): void {
		$agreeing = $this->table(
			'ANY',
			[
				['id' => 'r1', 'inputEntries' => ['-'], 'outputEntries' => ['boete']],
				['id' => 'r2', 'inputEntries' => ['-'], 'outputEntries' => ['boete']],
			]
		);

		$out = $this->node->execute(items: [$this->item(['severity' => 'x'])], config: ['table' => $agreeing], context: []);
		$this->assertSame('boete', $out[0][FlowItems::JSON]['intervention']);

		$disagreeing = $this->table(
			'ANY',
			[
				['id' => 'r1', 'inputEntries' => ['-'], 'outputEntries' => ['boete']],
				['id' => 'r2', 'inputEntries' => ['-'], 'outputEntries' => ['dwangsom']],
			]
		);

		$this->expectException(DecisionEvaluationException::class);
		$this->node->execute(items: [$this->item(['severity' => 'x'])], config: ['table' => $disagreeing], context: []);

	}//end testAnyAgreesOrFails()

	/**
	 * COLLECT writes one list per output, in declaration order, and an empty
	 * match writes empty lists rather than failing: "nothing applied" is an
	 * answer.
	 *
	 * @return void
	 */
	public function testCollectWritesListsAndEmptyMatchIsAnAnswer(): void {
		$table = $this->table(
			'COLLECT',
			[
				['id' => 'r1', 'inputEntries' => ['ernstig'], 'outputEntries' => ['boete']],
				['id' => 'r2', 'inputEntries' => ['in (ernstig, matig)'], 'outputEntries' => ['dwangsom']],
			]
		);

		$out = $this->node->execute(items: [$this->item(['severity' => 'ernstig'])], config: ['table' => $table], context: []);
		$this->assertSame(['boete', 'dwangsom'], $out[0][FlowItems::JSON]['intervention']);

		$out = $this->node->execute(items: [$this->item(['severity' => 'anders'])], config: ['table' => $table], context: []);
		$this->assertSame([], $out[0][FlowItems::JSON]['intervention']);

	}//end testCollectWritesListsAndEmptyMatchIsAnAnswer()

	/**
	 * No match without a default row is a loud failure for the onError
	 * policy, never a silent empty result.
	 *
	 * @return void
	 */
	public function testNoMatchWithoutDefaultsFailsLoudly(): void {
		$table = $this->table(
			'FIRST',
			[['id' => 'r1', 'inputEntries' => ['ernstig'], 'outputEntries' => ['boete']]]
		);

		$this->expectException(DecisionEvaluationException::class);
		$this->expectExceptionMessage('no_rule_matched');

		$this->node->execute(items: [$this->item(['severity' => 'anders'])], config: ['table' => $table], context: []);

	}//end testNoMatchWithoutDefaultsFailsLoudly()

	/**
	 * No match with a complete default row decides the default, and the
	 * evaluation record says so: defaulted, with no matched rule ids.
	 *
	 * @return void
	 */
	public function testNoMatchWithDefaultsDecidesTheDefault(): void {
		$table = $this->table(
			'FIRST',
			[['id' => 'r1', 'inputEntries' => ['ernstig'], 'outputEntries' => ['boete']]]
		);

		$out = $this->node->execute(
			items: [$this->item(['severity' => 'anders'])],
			config: [
				'table' => $table,
				'defaultOutputs' => ['intervention' => 'geen actie'],
				'resultKey' => 'evaluatie',
			],
			context: []
		);

		$json = $out[0][FlowItems::JSON];
		$this->assertSame('geen actie', $json['intervention']);
		$this->assertTrue($json['evaluatie']['defaulted']);
		$this->assertSame([], $json['evaluatie']['matchedRuleIds']);

	}//end testNoMatchWithDefaultsDecidesTheDefault()

	/**
	 * A missing input fails with the evaluator's typed error rather than
	 * being wildcarded: a decision over absent data is not a decision. The
	 * default row does NOT catch this — it answers "no rule matched", not
	 * "the question could not be asked".
	 *
	 * @return void
	 */
	public function testMissingInputFailsLoudlyEvenWithDefaults(): void {
		$this->expectException(DecisionEvaluationException::class);

		$this->node->execute(
			items: [$this->item(['behaviour' => 'opzettelijk', 'actorType' => 'bedrijf'])],
			config: [
				'table' => $this->lhsTable(),
				'defaultOutputs' => ['intervention' => 'geen actie'],
			],
			context: []
		);

	}//end testMissingInputFailsLoudlyEvenWithDefaults()

	/**
	 * Determinism: the same item against the same configuration decides the
	 * same way, and the second firing's records are identical to the first's.
	 *
	 * @return void
	 */
	public function testTheSameItemDecidesTheSameWayTwice(): void {
		$config = ['table' => $this->lhsTable(), 'resultKey' => 'evaluatie'];
		$items = [$this->item(['severity' => 'ernstig', 'behaviour' => 'onverschillig', 'actorType' => 'bedrijf'])];

		$first = $this->node->execute(items: $items, config: $config, context: []);
		$second = $this->node->execute(items: $items, config: $config, context: []);

		$this->assertSame($first, $second);
		$this->assertSame('last onder dwangsom', $first[0][FlowItems::JSON]['intervention']);

	}//end testTheSameItemDecidesTheSameWayTwice()

	/**
	 * Items are decided independently, provenance points each output item at
	 * its input, and binaries ride along untouched.
	 *
	 * @return void
	 */
	public function testItemsAreIndependentAndProvenanceIsKept(): void {
		$out = $this->node->execute(
			items: [
				FlowItems::item(json: ['severity' => 'licht', 'behaviour' => 'goedwillend', 'actorType' => 'burger'], binary: ['scan' => 'blob']),
				FlowItems::item(json: ['severity' => 'ernstig', 'behaviour' => 'opzettelijk', 'actorType' => 'bedrijf']),
			],
			config: ['table' => $this->lhsTable()],
			context: []
		);

		$this->assertCount(2, $out);
		$this->assertSame('waarschuwing', $out[0][FlowItems::JSON]['intervention']);
		$this->assertSame('bestuurlijke boete', $out[1][FlowItems::JSON]['intervention']);
		$this->assertSame(['scan' => 'blob'], $out[0][FlowItems::BINARY]);
		$this->assertSame(['item' => 0], $out[0][FlowItems::PAIRED_ITEM]);
		$this->assertSame(['item' => 1], $out[1][FlowItems::PAIRED_ITEM]);

	}//end testItemsAreIndependentAndProvenanceIsKept()

	/**
	 * An empty firing returns nothing and touches nothing.
	 *
	 * @return void
	 */
	public function testEmptyItemsReturnEmpty(): void {
		$this->assertSame([], $this->node->execute(items: [], config: [], context: []));

	}//end testEmptyItemsReturnEmpty()

	/**
	 * The save-path refusals, one per silent failure they close off.
	 *
	 * @return void
	 */
	public function testMissingTableIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->node->validateConfig(config: []);

	}//end testMissingTableIsRefused()

	/**
	 * An unimplemented hit policy is refused at save, by name.
	 *
	 * @return void
	 */
	public function testUnimplementedHitPolicyIsRefusedAtSave(): void {
		$table = $this->lhsTable();
		$table['hitPolicy'] = 'RULE ORDER';

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('RULE ORDER');

		$this->node->validateConfig(config: ['table' => $table]);

	}//end testUnimplementedHitPolicyIsRefusedAtSave()

	/**
	 * A malformed rule cell cannot be saved; the refusal names the rule and
	 * the column.
	 *
	 * @return void
	 */
	public function testMalformedCellIsRefusedAtSave(): void {
		// `>=` with no operand is malformed on any column type; the string
		// column cases that ARE executable (a bare `[5..` is a literal on a
		// string column) belong to the validator's own suite.
		$table = $this->lhsTable();
		$table['rules'][0]['inputEntries'][0] = '>=';

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('severity');

		$this->node->validateConfig(config: ['table' => $table]);

	}//end testMalformedCellIsRefusedAtSave()

	/**
	 * A mapping naming something the table does not declare is refused: the
	 * step would silently ignore it, which looks like behaviour and is not.
	 *
	 * @return void
	 */
	public function testMappingOverUndeclaredNameIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('spookveld');

		$this->node->validateConfig(
			config: [
				'table' => $this->lhsTable(),
				'outputMapping' => ['spookveld' => 'ergens'],
			]
		);

	}//end testMappingOverUndeclaredNameIsRefused()

	/**
	 * A templated mapping path is refused: a rule step's positions are the
	 * author's, literally, never the data's.
	 *
	 * @return void
	 */
	public function testTemplatedMappingPathIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('templated');

		$this->node->validateConfig(
			config: [
				'table' => $this->lhsTable(),
				'outputMapping' => ['intervention' => 'advies.{{veld}}'],
			]
		);

	}//end testTemplatedMappingPathIsRefused()

	/**
	 * A default row missing a declared output is refused: a partial default
	 * writes half a decision.
	 *
	 * @return void
	 */
	public function testPartialDefaultRowIsRefused(): void {
		$table = $this->lhsTable();
		$table['outputs'][] = ['name' => 'termijn', 'type' => 'string'];
		foreach ($table['rules'] as $index => $rule) {
			$table['rules'][$index]['outputEntries'][] = '6 weken';
		}

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('termijn');

		$this->node->validateConfig(
			config: [
				'table' => $table,
				'defaultOutputs' => ['intervention' => 'geen actie'],
			]
		);

	}//end testPartialDefaultRowIsRefused()

	/**
	 * A default row on COLLECT is refused: the empty list is the answer.
	 *
	 * @return void
	 */
	public function testDefaultsOnCollectAreRefused(): void {
		$table = $this->lhsTable();
		$table['hitPolicy'] = 'COLLECT';

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('COLLECT');

		$this->node->validateConfig(
			config: [
				'table' => $table,
				'defaultOutputs' => ['intervention' => 'geen actie'],
			]
		);

	}//end testDefaultsOnCollectAreRefused()

	/**
	 * The palette contract: the id, and a form field for every configurable
	 * key so the canvas never edits a key the node ignores.
	 *
	 * @return void
	 */
	public function testPaletteAndFormCoverTheVocabulary(): void {
		$this->assertSame('openregister.decision-table', $this->node->getId());
		$this->assertNotSame('', $this->node->getDisplayName());
		$this->assertNotSame('', $this->node->getDescription());

		$formKeys = array_map(static fn (array $field): string => (string)$field['key'], $this->node->configForm());
		$this->assertSame($this->node->configKeys(), $formKeys);

	}//end testPaletteAndFormCoverTheVocabulary()
}//end class
