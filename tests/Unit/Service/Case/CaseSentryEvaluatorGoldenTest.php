<?php

/**
 * Golden test for CaseSentryEvaluator.
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
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Exception\CaseValidationException;
use OCA\OpenRegister\Service\Case\CasePlanTree;
use OCA\OpenRegister\Service\Case\CaseSentryEvaluator;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for CaseSentryEvaluator: validateCriteria(), entrySentry(), exitSentry() and dataFor().
 *
 * Every criteria case is evaluated as entry and as exit criteria against two
 * plans (the milestone still available, and completed) and three events. The
 * full result grid, and the refusal message of every validation case, is
 * compared with fixtures/case-sentry-golden.json. Set UPDATE_GOLDEN=1 to
 * rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Case\CaseSentryEvaluator
 * @covers \OCA\OpenRegister\Service\Case\CaseSentryOnPart
 * @covers \OCA\OpenRegister\Service\Case\CaseSentryMoment
 * @uses   \OCA\OpenRegister\Service\Flow\EventCatalogService
 * @uses   \OCA\OpenRegister\Exception\CaseValidationException
 * @uses   \OCA\OpenRegister\Db\CaseItem
 * @uses   \OCA\OpenRegister\Service\Case\CasePlanTree
 * @uses   \OCA\OpenRegister\Service\Flow\FlowExpression
 */
class CaseSentryEvaluatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/case-sentry-golden.json';

	/**
	 * @return array<string, mixed> Name => criteria.
	 */
	private static function criteria(): array {
		$done = ['event' => 'case.item.completed', 'item' => 'advice'];
		$var  = ['==' => [['var' => 'case.object.amount'], 5]];
		return [
			'null' => null,
			'empty' => [],
			'scalar' => 'x',
			'scalar sentry' => ['x'],
			'empty sentry' => [[]],
			'other keys only' => [['id' => 'z', 'when' => true]],
			'on item done' => [['id' => 'done', 'on' => $done]],
			'on item done no id' => [['on' => $done]],
			'on item done second position no id' => ['junk', ['on' => $done]],
			'on item terminated' => [['on' => ['event' => 'case.item.terminated', 'item' => 'advice']]],
			'on item disabled other item' => [['on' => ['event' => 'case.item.disabled', 'item' => 'decide']]],
			'on item without item' => [['on' => ['event' => 'case.item.completed']]],
			'on item blank item' => [['on' => ['event' => 'case.item.completed', 'item' => '  ']]],
			'on padded event' => [['on' => ['event' => ' case.item.completed ', 'item' => ' advice ']]],
			'on object event' => [['id' => 'upd', 'on' => ['event' => 'object.updated']]],
			'on legacy alias' => [['id' => 'cre', 'on' => ['event' => 'created']]],
			'on unknown event' => [['on' => ['event' => 'nope.happened']]],
			'on blank event' => [['on' => ['event' => '']]],
			'on missing event' => [['on' => ['item' => 'advice']]],
			'on scalar' => [['on' => 'object.updated']],
			'on null' => [['on' => null]],
			'if true literal' => [['if' => true]],
			'if list literal' => [['if' => [['var' => 'json.amount']]]],
			'if empty' => [['if' => []]],
			'if no var' => [['if' => ['==' => [1, 1]]]],
			'if var holds' => [['id' => 'amt', 'if' => $var]],
			'if var fails' => [['id' => 'amt', 'if' => ['==' => [['var' => 'json.amount'], 6]]]],
			'if nested var' => [['if' => ['and' => [true, ['!' => [['!' => [['var' => 'case.items.advice']]]]]]]]],
			'if case document' => [['if' => ['==' => [['var' => 'case.items.advice'], 'completed']]]],
			'if event document' => [['if' => ['==' => [['var' => 'case.event'], 'object.updated']]]],
			'if payload document' => [['if' => ['==' => [['var' => 'context.payload.flag'], true]]]],
			'if invalid operator' => [['if' => ['nosuchop' => [['var' => 'x']]]]],
			'on and if both hold' => [['id' => 'both', 'on' => ['event' => 'object.updated'], 'if' => $var]],
			'on holds if fails' => [['id' => 'both', 'on' => ['event' => 'object.updated'], 'if' => ['==' => [['var' => 'subject.amount'], 9]]]],
			'or across first wins' => [['id' => 'a', 'if' => $var], ['id' => 'b', 'on' => $done]],
			'or across later wins' => [
				['id' => 'a', 'if' => ['==' => [1, 1]]],
				['id' => 'b', 'on' => ['event' => 'object.updated']],
				['id' => 'c', 'if' => $var],
			],
			'numeric id' => [['id' => 7, 'if' => $var]],
			'keyed criteria' => ['x' => ['if' => $var], 'y' => ['on' => $done]],
		];
	}//end criteria()

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$out = [];
		foreach (array_keys(self::criteria()) as $name) {
			$out[$name] = [$name];
		}

		return $out;
	}//end cases()

	/**
	 * Record what the evaluator answers for one criteria case.
	 *
	 * @param string $name The criteria case name.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testMatchesGolden(string $name): void {
		$criteria  = self::criteria()[$name];
		$evaluator = new CaseSentryEvaluator(catalog: new EventCatalogService());
		$actual    = ['validate' => $this->validation(evaluator: $evaluator, criteria: $criteria)];

		foreach ([CaseItem::STATE_AVAILABLE, CaseItem::STATE_COMPLETED] as $adviceState) {
			$advice = CaseFixtures::row(id: 1, key: 'advice', type: CaseItem::TYPE_MILESTONE, state: $adviceState);
			$item   = CaseFixtures::row(id: 2, key: 'decide', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
			if (is_array($criteria) === true) {
				$item->setEntryCriteria($criteria);
				$item->setExitCriteria($criteria);
			}

			$tree = new CasePlanTree(items: [$advice, $item]);
			foreach ([null, 'object.updated', 'object.created'] as $event) {
				$label = $adviceState . ' / ' . ($event ?? 'no event');
				foreach ([['amount' => 5], ['amount' => 6]] as $object) {
					$key = $label . ' / amount ' . $object['amount'];
					$payload = ['flag' => $object['amount'] === 5];
					$actual['entry'][$key] = $evaluator->entrySentry($item, $tree, $object, $event, $payload);
					$actual['exit'][$key]  = $evaluator->exitSentry($item, $tree, $object, $event, $payload);
				}
			}

			$actual['data'][$adviceState] = $evaluator->dataFor($tree, ['amount' => 5], 'object.updated', ['flag' => true]);
		}//end foreach

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
	 * The refusal message validateCriteria() throws for the criteria, or "ok".
	 *
	 * @param CaseSentryEvaluator $evaluator The evaluator.
	 * @param mixed               $criteria  The criteria.
	 *
	 * @return string The message.
	 */
	private function validation(CaseSentryEvaluator $evaluator, mixed $criteria): string {
		try {
			$evaluator->validateCriteria($criteria, 'entry of decide');
		} catch (CaseValidationException $e) {
			return $e->getMessage();
		}

		return 'ok';
	}//end validation()
}//end class
