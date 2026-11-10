<?php

/**
 * Every shape refusal of an object action definition, each named exactly,
 * against the clean definition each one differs from.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Service\ObjectAction\ObjectActionEntry;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionShape;
use OCA\OpenRegister\Service\ObjectAction\SchemaRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Refusal cases for {@see ObjectActionShape}.
 */
class ObjectActionShapeRefusalCasesTest extends TestCase {

	/**
	 * Definitions and the refusals they draw.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<int, string>}>
	 */
	public static function cases(): array {
		$writeOnly = ['type' => 'object', 'properties' => ['pin' => ['type' => 'string', 'writeOnly' => true]]];

		return [
			'blank transition' => [['transition' => ' '], [], ['Action "act": "transition" must name a lifecycle transition.']],
			'undeclared transition' => [['transition' => 'reopen'], ['transitions' => ['close' => []]], ['Action "act": the lifecycle declares no transition "reopen".']],
			'transition with non-map lifecycle transitions' => [['transition' => 'reopen'], ['transitions' => 'close'], []],
			'declared transition' => [['transition' => 'close'], ['transitions' => ['close' => []]], []],
			'steps not a list' => [['steps' => ['a' => ['type' => 'x']]], [], ['Action "act": "steps" must be a non-empty list of flow steps.']],
			'empty steps' => [['steps' => []], [], ['Action "act": "steps" must be a non-empty list of flow steps.']],
			'step without type' => [['steps' => [['type' => 'log'], ['type' => ' ']]], [], ['Action "act": step 2 must name its node "type".']],
			'scalar step' => [['steps' => ['log']], [], ['Action "act": step 1 must name its node "type".']],
			'step config not object' => [['steps' => [['type' => 'log', 'config' => 'x']]], [], ['Action "act": step 1 has a "config" that is not an object.']],
			'valid steps' => [['steps' => [['type' => 'log', 'config' => []]]], [], []],
			'inputs not object schema' => [['transition' => 'close', 'inputs' => ['type' => 'array']], [], ['Action "act": "inputs" must be a JSON Schema of type "object".']],
			'inputs properties not a map' => [['transition' => 'close', 'inputs' => ['type' => 'object', 'properties' => 'x']], [], ['Action "act": "inputs" must be a JSON Schema of type "object".']],
			'unknown surface' => [['transition' => 'close', 'surfaces' => ['header', 'footer']], [], ['Action "act": "surfaces" may only name header, row, bulk, detail-menu.']],
			'surfaces not a list' => [['transition' => 'close', 'surfaces' => 'row'], [], ['Action "act": "surfaces" may only name header, row, bulk, detail-menu.']],
			'object default on bulk' => [
				['transition' => 'close', 'surfaces' => ['bulk'], 'inputs' => ['type' => 'object', 'properties' => ['t' => ['default' => '@object.title']]]],
				[],
				['Action "act": an input defaults from "@object", so the action cannot be offered on "bulk".'],
			],
			'object default off bulk' => [['transition' => 'close', 'surfaces' => ['row'], 'inputs' => ['type' => 'object', 'properties' => ['t' => ['default' => '@object.title']]]], [], []],
			'unknown mode' => [['transition' => 'close', 'mode' => 'later'], [], ['Action "act": "mode" must be "sync" or "async".']],
			'async transition' => [['transition' => 'close', 'mode' => 'async'], [], ['Action "act": a transition is one write and always runs sync.']],
			'async writeOnly input' => [['steps' => [['type' => 'log']], 'mode' => 'async', 'inputs' => $writeOnly], [], ['Action "act": a writeOnly input is never stored, so the action cannot run async.']],
			'async steps' => [['steps' => [['type' => 'log']], 'mode' => 'async'], [], []],
			'two bodies' => [['transition' => 'close', 'steps' => [['type' => 'log']]], [], ['Action "act": an action has one body, but this one declares transition and steps.']],
			'malformed selfAssign' => [['selfAssign' => true], [], ['Action "act": "selfAssign" must be {"field": "<property>"} and nothing else.']],
			'invalid when' => [['transition' => 'close', 'when' => ['nope' => [1]]], [], ['Action "act": "when" is not valid JSONLogic.']],
			'valid when' => [['transition' => 'close', 'when' => ['==' => [1, 1]]], [], []],
			'unknown next' => [['transition' => 'close', 'next' => 'elsewhere'], [], ['Action "act": "next" must be one of stay, next, list.']],
		];
	}//end cases()

	/**
	 * Each definition draws exactly its refusals.
	 *
	 * @param array<string, mixed> $definition The action definition.
	 * @param array<string, mixed> $lifecycle The schema lifecycle.
	 * @param array<int, string> $expected The refusals.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testEachDefinitionDrawsExactlyItsRefusals(array $definition, array $lifecycle, array $expected): void {
		$this->assertSame($expected, ObjectActionShape::refusals(action: new ObjectActionEntry(key: 'act', definition: $definition), rules: new SchemaRules(lifecycle: $lifecycle, authorization: null)));
	}//end testEachDefinitionDrawsExactlyItsRefusals()
}//end class
