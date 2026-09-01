<?php

/**
 * The definition boundary: what is refused at save time, and what a row
 * compiled from a valid node looks like.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use DateTime;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Exception\CaseValidationException;
use OCA\OpenRegister\Service\Case\CasePlanDefinition;
use OCA\OpenRegister\Service\Case\CaseSentryEvaluator;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use PHPUnit\Framework\TestCase;

/**
 * Coverage of CasePlanDefinition.
 *
 * @covers \OCA\OpenRegister\Service\Case\CasePlanDefinition
 */
class CasePlanDefinitionTest extends TestCase {

	/**
	 * The compiler over the real evaluator and catalog.
	 *
	 * @return CasePlanDefinition The compiler.
	 */
	private function definitions(): CasePlanDefinition {
		return new CasePlanDefinition(sentries: new CaseSentryEvaluator(catalog: new EventCatalogService()));
	}//end definitions()

	/**
	 * A valid two-stage definition normalises, collecting flow bindings.
	 *
	 * @return void
	 */
	public function testAValidDefinitionNormalises(): void {
		$normalised = $this->definitions()->validate(definition: self::permitDefinition());

		$this->assertSame(['demo-behandelaars'], $normalised['settings']['authorization']);
		$this->assertSame(['run-stage' => 'flow-uuid-1'], $normalised['settings']['flows']);
		$this->assertCount(3, $normalised['items']);
		$this->assertSame([], $normalised['items'][0]['children'][0]['children'], 'A leaf gets an empty children list.');
		$this->assertSame('assessment', $normalised['items'][1]['key']);
	}//end testAValidDefinitionNormalises()

	/**
	 * Every refusal the boundary makes.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$definitions = $this->definitions();
		$item = static fn (array $over): array => ['items' => [array_merge(['key' => 'a', 'type' => CaseItem::TYPE_HUMAN_TASK], $over)]];
		$refusals = [
			'no items' => ['items' => []],
			'settings not object' => ['items' => [['key' => 'a', 'type' => 'stage']], 'settings' => 'x'],
			'bad root rules' => ['items' => [['key' => 'a', 'type' => 'stage']], 'settings' => ['authorization' => [1]]],
			'bad results' => ['items' => [['key' => 'a', 'type' => 'stage']], 'settings' => ['results' => 'verleend']],
			'bad writeThrough' => ['items' => [['key' => 'a', 'type' => 'stage']], 'settings' => ['writeThrough' => 'status']],
			'node not object' => ['items' => ['a']],
			'bad key' => $item(['key' => 'has space']),
			'unknown type' => $item(['type' => 'processTask']),
			'unknown event' => $item(['entryCriteria' => [['on' => ['event' => 'case.item.started']]]]),
			'bad exit if' => $item(['exitCriteria' => [['if' => ['nonsuch' => 1]]]]),
			'bad rules' => $item(['authorization' => 'behandelaars']),
			'bad repetition' => $item(['repetition' => ['max' => 0]]),
			'discretionary milestone' => $item(['type' => CaseItem::TYPE_MILESTONE, 'discretionary' => true]),
			'children on a task' => $item(['children' => []]),
			'flow on a task' => $item(['flow' => 'f']),
			'children not list' => ['items' => [['key' => 'a', 'type' => 'stage', 'children' => 'x']]],
			'flow and children' => ['items' => [['key' => 'a', 'type' => 'stage', 'flow' => 'f', 'children' => [['key' => 'b', 'type' => 'milestone']]]]],
			'duplicate key' => ['items' => [['key' => 'a', 'type' => 'stage', 'children' => [['key' => 'a', 'type' => 'milestone']]]]],
		];
		foreach ($refusals as $label => $definition) {
			try {
				$definitions->validate(definition: $definition);
				$this->fail("$label must be refused");
			} catch (CaseValidationException $refusal) {
				$this->assertNotSame('', $refusal->getMessage(), $label);
			}
		}

		$this->assertStringContainsString('case.item.started', $this->refusalMessage($definitions, $item(['entryCriteria' => [['on' => ['event' => 'case.item.started']]]])));
	}//end testRefusals()

	/**
	 * An ad-hoc item may not guard itself, be discretionary, nest, or bind a flow.
	 *
	 * @return void
	 */
	public function testAdHocRefusals(): void {
		$definitions = $this->definitions();
		foreach ([
			['key' => 'x', 'type' => 'humanTask', 'authorization' => []],
			['key' => 'x', 'type' => 'humanTask', 'discretionary' => true],
			['key' => 'x', 'type' => 'stage', 'children' => []],
			['key' => 'x', 'type' => 'stage', 'flow' => 'f'],
		] as $node) {
			try {
				$definitions->validateAdHoc(node: $node);
				$this->fail('must be refused');
			} catch (CaseValidationException) {
				$this->addToAssertionCount(1);
			}
		}

		$ok = $definitions->validateAdHoc(node: ['key' => 'advice', 'type' => 'humanTask', 'name' => 'Advies']);
		$this->assertSame('advice', $ok['key']);
	}//end testAdHocRefusals()

	/**
	 * A compiled row carries every field, starts available and non-terminal,
	 * and an ad-hoc row has no definition key.
	 *
	 * @return void
	 */
	public function testRowFromCompilesEveryField(): void {
		$definitions = $this->definitions();
		$node = [
			'key' => 'check',
			'type' => CaseItem::TYPE_HUMAN_TASK,
			'name' => ' Controle ',
			'description' => 'd',
			'required' => false,
			'discretionary' => true,
			'entryCriteria' => [['if' => ['var' => 'json.a']]],
			'repetition' => ['max' => 2],
			'authorization' => ['g'],
			'candidateUsers' => ['u1'],
			'candidateGroups' => ['g1'],
			'candidateRole' => 'r',
			'dueAt' => '2026-09-04T17:00:00+02:00',
			'doorlooptijd' => 'P8W',
			'servicenorm' => 'P6W',
		];
		$row = $definitions->rowFrom(node: $node, objectUuid: 'obj', registerId: 1, schemaId: 2, parentId: 9, position: 3, settings: ['a' => 1], origin: CaseItem::ORIGIN_DISCRETIONARY, actor: 'alice', flowUuid: 'f', flowVersion: 4);

		$this->assertSame('check', $row->getItemKey());
		$this->assertSame('check', $row->getDefinitionItemKey());
		$this->assertSame('Controle', $row->getName());
		$this->assertSame(CaseItem::STATE_AVAILABLE, $row->getState());
		$this->assertFalse($row->getIsTerminal());
		$this->assertFalse($row->getRequired());
		$this->assertTrue($row->getDiscretionary());
		$this->assertSame(['max' => 2], $row->getRepetition());
		$this->assertSame(['g'], $row->getAuthorizationRules());
		$this->assertSame(['u1'], $row->getCandidateUsers());
		$this->assertSame('r', $row->getCandidateRole());
		$this->assertInstanceOf(DateTime::class, $row->getDueAt());
		$this->assertNull($row->getExpiresAt());
		$this->assertSame('P8W', $row->getDoorlooptijd());
		$this->assertSame(9, $row->getParentItemId());
		$this->assertSame(3, $row->getPosition());
		$this->assertSame(['a' => 1], $row->getPlanSettings());
		$this->assertSame('alice', $row->getCreatedBy());
		$this->assertSame('f', $row->getFlowUuid());
		$this->assertSame(4, $row->getFlowVersion());
		$this->assertSame(1, $row->getRealisationCount());

		$adhoc = $definitions->rowFrom(node: ['key' => 'x', 'type' => 'milestone'], objectUuid: 'obj', registerId: null, schemaId: null, parentId: null, position: 0, settings: [], origin: CaseItem::ORIGIN_ADHOC, actor: null);
		$this->assertNull($adhoc->getDefinitionItemKey(), 'An ad-hoc item is in no definition.');
		$this->assertTrue($adhoc->getRequired(), 'Required defaults to true.');
		$this->assertNull($adhoc->getEntryCriteria());

		$this->expectException(CaseValidationException::class);
		$definitions->rowFrom(node: ['key' => 'x', 'type' => 'milestone', 'dueAt' => 'not a date'], objectUuid: 'obj', registerId: null, schemaId: null, parentId: null, position: 0, settings: [], origin: CaseItem::ORIGIN_DEFINED, actor: null);
	}//end testRowFromCompilesEveryField()

	/**
	 * The message of a refusal.
	 *
	 * @param CasePlanDefinition $definitions The compiler.
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return string The message.
	 */
	private function refusalMessage(CasePlanDefinition $definitions, array $definition): string {
		try {
			$definitions->validate(definition: $definition);
		} catch (CaseValidationException $refusal) {
			return $refusal->getMessage();
		}

		return '';
	}//end refusalMessage()

	/**
	 * The two-stage permit definition (design.md seed 1 + 2), plus a flow-bound stage.
	 *
	 * @return array<string, mixed> The definition.
	 */
	public static function permitDefinition(): array {
		return [
			'settings' => [
				'authorization' => ['demo-behandelaars'],
				'results' => ['verleend', 'geweigerd'],
				'writeThrough' => ['statusField' => 'status', 'statusAtField' => 'statusReachedAt', 'resultField' => 'resultaat'],
			],
			'items' => [
				[
					'key' => 'intake',
					'type' => CaseItem::TYPE_STAGE,
					'name' => 'Intake',
					'children' => [
						['key' => 'completeness-check', 'type' => CaseItem::TYPE_HUMAN_TASK, 'name' => 'Controleer volledigheid', 'candidateGroups' => ['demo-behandelaars']],
						['key' => 'application-complete', 'type' => CaseItem::TYPE_MILESTONE, 'name' => 'Aanvraag volledig', 'entryCriteria' => [['id' => 'complete', 'on' => ['event' => 'case.item.completed', 'item' => 'completeness-check']]]],
					],
				],
				[
					'key' => 'assessment',
					'type' => CaseItem::TYPE_STAGE,
					'name' => 'Beoordeling',
					'entryCriteria' => [['id' => 'after-intake', 'on' => ['event' => 'case.item.completed', 'item' => 'application-complete']]],
					'children' => [
						['key' => 'external-advice', 'type' => CaseItem::TYPE_HUMAN_TASK, 'name' => 'Extern advies', 'discretionary' => true, 'required' => false, 'authorization' => ['demo-beslissers']],
						['key' => 'decide', 'type' => CaseItem::TYPE_HUMAN_TASK, 'name' => 'Besluit', 'candidateGroups' => ['demo-beslissers']],
					],
				],
				['key' => 'run-stage', 'type' => CaseItem::TYPE_STAGE, 'name' => 'Automated', 'required' => false, 'flow' => 'flow-uuid-1', 'entryCriteria' => [['id' => 'never', 'if' => ['==' => [['var' => 'json.automate'], true]]]]],
			],
		];
	}//end permitDefinition()
}//end class
