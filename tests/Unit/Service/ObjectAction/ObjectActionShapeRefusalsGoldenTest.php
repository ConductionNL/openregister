<?php

/**
 * Golden test for ObjectActionShape::refusals().
 *
 * Pins the complete ordered refusal list for declared actions that reach
 * every body, input, surface, mode and next-hint branch, ahead of a
 * parameter refactor of the private refusal steps.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Service\ObjectAction\ObjectActionEntry;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionShape;
use OCA\OpenRegister\Service\ObjectAction\SchemaRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden pins for the shape refusals of a declared action.
 *
 * @covers \OCA\OpenRegister\Service\ObjectAction\ObjectActionShape
 */
class ObjectActionShapeRefusalsGoldenTest extends TestCase {

	private const LIFECYCLE = ['transitions' => ['submit' => ['from' => 'draft', 'to' => 'submitted']]];

	/**
	 * Declared actions with the lifecycle they are judged against, and the pinned refusals.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: list<string>}>
	 */
	public static function actionProvider(): array {
		return [
			'clean transition'        => [['transition' => 'submit'], self::LIFECYCLE, []],
			'two bodies'              => [['transition' => 'submit', 'flow' => 'x', 'mode' => 'bogus'], self::LIFECYCLE, ['Action "act": an action has one body, but this one declares transition and flow.']],
			'blank transition'        => [['transition' => ' '], self::LIFECYCLE, ['Action "act": "transition" must name a lifecycle transition.']],
			'non-string transition'   => [['transition' => 5], [], ['Action "act": "transition" must name a lifecycle transition.']],
			'unknown transition'      => [['transition' => 'approve'], self::LIFECYCLE, ['Action "act": the lifecycle declares no transition "approve".']],
			'no lifecycle'            => [['transition' => 'approve'], [], []],
			'odd transitions'         => [['transition' => 'approve'], ['transitions' => 'none'], []],
			'async transition'        => [['transition' => 'submit', 'mode' => 'async'], self::LIFECYCLE, ['Action "act": a transition is one write and always runs sync.']],
			'steps not a list'        => [['steps' => ['a' => ['type' => 'x']]], [], ['Action "act": "steps" must be a non-empty list of flow steps.']],
			'steps empty'             => [['steps' => []], [], ['Action "act": "steps" must be a non-empty list of flow steps.']],
			'step without type'       => [['steps' => [['type' => 'ok'], ['config' => []]]], [], ['Action "act": step 2 must name its node "type".']],
			'step not an array'       => [['steps' => ['x']], [], ['Action "act": step 1 must name its node "type".']],
			'step with bad config'    => [['steps' => [['type' => 'ok', 'config' => 'x']]], [], ['Action "act": step 1 has a "config" that is not an object.']],
			'clean steps'             => [['steps' => [['type' => 'ok', 'config' => []], ['type' => 'two']]], [], []],
			'self-assign extras'      => [['selfAssign' => ['field' => 'owner'], 'mode' => 'async'], [], [
				'Action "act": a self-assign action takes no mode.',
			]],
			'no body, bad everything' => [
				['inputs' => ['type' => 'array'], 'when' => ['bogus_op' => [1]], 'surfaces' => ['nowhere'], 'mode' => 'later', 'next' => 'away'],
				[],
				[
					'Action "act": "inputs" must be a JSON Schema of type "object".',
					'Action "act": "when" is not valid JSONLogic.',
					'Action "act": "surfaces" may only name header, row, bulk, detail-menu.',
					'Action "act": "mode" must be "sync" or "async".',
					'Action "act": "next" must be one of stay, next, list.',
				],
			],
			'inputs with odd props'   => [['flow' => 'f', 'inputs' => ['type' => 'object', 'properties' => 'x']], [], ['Action "act": "inputs" must be a JSON Schema of type "object".']],
			'bulk with @object'       => [
				['flow' => 'f', 'surfaces' => ['bulk'], 'inputs' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'default' => '@object.title']]]],
				[],
				['Action "act": an input defaults from "@object", so the action cannot be offered on "bulk".'],
			],
			'surfaces not a list'     => [['flow' => 'f', 'surfaces' => 'row'], [], ['Action "act": "surfaces" may only name header, row, bulk, detail-menu.']],
			'async writeOnly'         => [
				['flow' => 'f', 'mode' => 'async', 'inputs' => ['type' => 'object', 'properties' => ['pin' => ['type' => 'string', 'writeOnly' => true]]]],
				[],
				['Action "act": a writeOnly input is never stored, so the action cannot run async.'],
			],
			'async clean, next ok'    => [['flow' => 'f', 'mode' => 'async', 'next' => 'list'], [], []],
			'elevate async'           => [['flow' => 'f', 'mode' => 'async', 'elevate' => [['register' => 'r', 'schema' => 's', 'verbs' => ['read']]]], [], [
				'Action "act": an elevated action runs sync; a queued run would execute after its elevation has ended.',
			]],
			'elevate not a list'      => [['flow' => 'f', 'elevate' => 'all'], [], ['Action "act": "elevate" must be a non-empty list of {register, schema, verbs}.']],
		];
	}//end actionProvider()

	/**
	 * The refusals are pinned, order and wording included.
	 *
	 * @param array<string, mixed> $definition The declared entry.
	 * @param array<string, mixed> $lifecycle  The schema lifecycle.
	 * @param list<string>         $expected   The pinned refusals.
	 *
	 * @return void
	 */
	#[DataProvider('actionProvider')]
	public function testRefusals(array $definition, array $lifecycle, array $expected): void {
		$this->assertSame($expected, ObjectActionShape::refusals(action: new ObjectActionEntry(key: 'act', definition: $definition), rules: new SchemaRules(lifecycle: $lifecycle, authorization: null)));
	}//end testRefusals()
}//end class
