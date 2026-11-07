<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Service\Handoff\HandoffAnnotationValidator}.
 *
 * Pins the exact error list `validate()` returns — codes, messages and order —
 * for a spread of `x-openregister-handoff` annotations that reach every rule:
 * malformed blocks and entries, bad and duplicate ids, target URIs, every
 * trigger branch, mapping fields and expressions, `whenUnavailable` and
 * `onSuccess`. Written against the code before its refactor and left
 * unchanged by it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Handoff;

use OCA\OpenRegister\Service\Handoff\HandoffAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * @no-named-arguments
 */
class HandoffAnnotationValidatorGoldenTest extends TestCase {

	private const CASE_URI = 'https://openregister.app/ns#Case';

	/**
	 * @return array<string, mixed>
	 */
	private static function goodEntry(): array {
		return [
			'id' => 'to-case',
			'targetSemanticType' => self::CASE_URI,
			'trigger' => 'lifecycle:ingediend',
			'mapping' => [
				'title' => ['from' => 'onderwerp', 'default' => 'x'],
				'summary' => ['template' => '{{ a }}'],
				'channel' => ['const' => 'web'],
				'source' => ['provenance' => true],
			],
			'whenUnavailable' => 'queue',
			'onSuccess' => ['set' => ['status' => 'doorgezet']],
		];
	}//end goodEntry()

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		$properties = ['onderwerp' => [], 'status' => ['enum' => ['nieuw', 'ingediend', 3]]];
		$lifecycle = ['field' => 'status'];
		$good = self::goodEntry();

		return [
			'absent' => ['properties' => $properties],
			'null block' => ['x-openregister-handoff' => null],
			'scalar block' => ['x-openregister-handoff' => 'x'],
			'empty block' => ['x-openregister-handoff' => []],
			'good' => ['properties' => $properties, 'x-openregister-lifecycle' => $lifecycle, 'x-openregister-handoff' => [$good]],
			'good via property key' => [
				'properties' => $properties,
				'x-openregister-lifecycle' => ['property' => 'status'],
				'x-openregister-handoff' => ['a' => 'scalar', 'b' => $good, 'c' => $good],
			],
			'bad ids' => [
				'x-openregister-handoff' => [
					['id' => 'Bad_Id', 'targetSemanticType' => 'ftp://x.example/y', 'mapping' => ['x' => 'y']],
					['targetSemanticType' => 'not a url', 'mapping' => []],
					['id' => 7, 'targetSemanticType' => 'https://example.org/Thing', 'mapping' => ['anything' => ['from' => 'a']]],
				],
			],
			'triggers' => [
				'properties' => $properties,
				'x-openregister-lifecycle' => $lifecycle,
				'x-openregister-handoff' => [
					array_merge($good, ['id' => 't1', 'trigger' => 'manual']),
					array_merge($good, ['id' => 't2', 'trigger' => 'auto']),
					array_merge($good, ['id' => 't3', 'trigger' => 5]),
					array_merge($good, ['id' => 't4', 'trigger' => 'lifecycle:']),
					array_merge($good, ['id' => 't5', 'trigger' => 'lifecycle:gesloten']),
					array_merge($good, ['id' => 't6', 'trigger' => 'lifecycle:3']),
					array_merge($good, ['id' => 't7', 'trigger' => null]),
				],
			],
			'no lifecycle' => ['properties' => $properties, 'x-openregister-handoff' => [$good]],
			'lifecycle without enum' => ['properties' => ['status' => []], 'x-openregister-lifecycle' => $lifecycle, 'x-openregister-handoff' => [$good]],
			'lifecycle field not a string' => ['x-openregister-lifecycle' => ['field' => 3], 'x-openregister-handoff' => [$good]],
			'lifecycle scalar' => ['x-openregister-lifecycle' => 'status', 'x-openregister-handoff' => [$good]],
			'mappings' => [
				'properties' => $properties,
				'x-openregister-lifecycle' => $lifecycle,
				'x-openregister-handoff' => [
					array_merge(
						$good,
						[
							'mapping' => [
								'title' => 'onderwerp',
								'colour' => ['from' => 'a'],
								'summary' => ['from' => 'a', 'const' => 'b'],
								'channel' => ['default' => 'x'],
								'requester' => ['from' => 'a', 'weird' => 1, 'odd' => 2],
								0 => ['semanticRef' => 'x'],
							],
						]
					),
				],
			],
			'unavailable and success' => [
				'properties' => $properties,
				'x-openregister-lifecycle' => $lifecycle,
				'x-openregister-handoff' => [
					array_merge($good, ['id' => 'u1', 'whenUnavailable' => 'explode', 'onSuccess' => ['set' => []]]),
					array_merge($good, ['id' => 'u2', 'whenUnavailable' => null, 'onSuccess' => 'yes']),
					array_merge($good, ['id' => 'u3', 'onSuccess' => ['set' => ['status' => 1, 'missing' => 2, 4 => 3]]]),
					array_merge($good, ['id' => 'u4', 'onSuccess' => ['other' => 1]]),
					array_merge($good, ['id' => 'u5', 'onSuccess' => null]),
				],
			],
			'properties scalar' => ['properties' => 'x', 'x-openregister-handoff' => [array_merge($good, ['trigger' => 'manual'])]],
		];
	}//end schemas()

	/**
	 * @return void
	 */
	public function testValidateErrors(): void {
		$validator = new HandoffAnnotationValidator();
		$actual = [];
		foreach (self::schemas() as $name => $schema) {
			$actual[$name] = $validator->validate($schema);
		}

		$this->assertSame(self::expectedErrors(), $actual);
	}//end testValidateErrors()

	/**
	 * @return array<string, array<int, array{code: string, message: string}>>
	 */
	private static function expectedErrors(): array {
		return [
			'absent' => [],
			'null block' => [],
			'scalar block' => [
				[
					'code' => 'handoff-bad-annotation',
					'message' => 'x-openregister-handoff must be an array of handoff entries.',
				],
			],
			'empty block' => [],
			'good' => [],
			'good via property key' => [
				[
					'code' => 'handoff-bad-annotation',
					'message' => 'x-openregister-handoff entry 0 must be an object.',
				],
				[
					'code' => 'handoff-duplicate-id',
					'message' => 'x-openregister-handoff id "to-case" is declared more than once.',
				],
			],
			'bad ids' => [
				[
					'code' => 'handoff-bad-id',
					'message' => 'x-openregister-handoff entry 0 must declare a slug-like `id` (lowercase letters, digits, hyphens).',
				],
				[
					'code' => 'handoff-bad-target-type',
					'message' => 'Handoff "entry-0": `targetSemanticType` must be an absolute kind URI (e.g. "https://openregister.app/ns#Case").',
				],
				[
					'code' => 'handoff-bad-mapping-expression',
					'message' => 'Handoff "entry-0": mapping for "x" must be an object using one of: from, const, template, semanticRef, provenance.',
				],
				[
					'code' => 'handoff-bad-id',
					'message' => 'x-openregister-handoff entry 1 must declare a slug-like `id` (lowercase letters, digits, hyphens).',
				],
				[
					'code' => 'handoff-bad-target-type',
					'message' => 'Handoff "entry-1": `targetSemanticType` must be an absolute kind URI (e.g. "https://openregister.app/ns#Case").',
				],
				[
					'code' => 'handoff-bad-annotation',
					'message' => 'Handoff "entry-1": a non-empty `mapping` object is required.',
				],
				[
					'code' => 'handoff-bad-id',
					'message' => 'x-openregister-handoff entry 2 must declare a slug-like `id` (lowercase letters, digits, hyphens).',
				],
			],
			'triggers' => [
				[
					'code' => 'handoff-bad-trigger',
					'message' => 'Handoff "t2": `trigger` must be "manual" or "lifecycle:<state>".',
				],
				[
					'code' => 'handoff-bad-trigger',
					'message' => 'Handoff "t3": `trigger` must be "manual" or "lifecycle:<state>".',
				],
				[
					'code' => 'handoff-bad-trigger',
					'message' => 'Handoff "t4": lifecycle trigger must name a state ("lifecycle:<state>").',
				],
				[
					'code' => 'handoff-bad-trigger',
					'message' => 'Handoff "t5": lifecycle state "gesloten" is not declared in the schema\'s lifecycle enum (nieuw, ingediend, 3).',
				],
			],
			'no lifecycle' => [
				[
					'code' => 'handoff-bad-trigger',
					'message' => 'Handoff "to-case": trigger "lifecycle:ingediend" requires the schema to declare x-openregister-lifecycle.',
				],
			],
			'lifecycle without enum' => [
				[
					'code' => 'handoff-bad-trigger',
					'message' => 'Handoff "to-case": trigger "lifecycle:ingediend" requires the schema to declare x-openregister-lifecycle.',
				],
			],
			'lifecycle field not a string' => [
				[
					'code' => 'handoff-bad-trigger',
					'message' => 'Handoff "to-case": trigger "lifecycle:ingediend" requires the schema to declare x-openregister-lifecycle.',
				],
				[
					'code' => 'handoff-bad-success-update',
					'message' => 'Handoff "to-case": onSuccess.set names property "status" which the source schema does not define.',
				],
			],
			'lifecycle scalar' => [
				[
					'code' => 'handoff-bad-trigger',
					'message' => 'Handoff "to-case": trigger "lifecycle:ingediend" requires the schema to declare x-openregister-lifecycle.',
				],
				[
					'code' => 'handoff-bad-success-update',
					'message' => 'Handoff "to-case": onSuccess.set names property "status" which the source schema does not define.',
				],
			],
			'mappings' => [
				[
					'code' => 'handoff-bad-mapping-expression',
					'message' => 'Handoff "to-case": mapping for "title" must be an object using one of: from, const, template, semanticRef, provenance.',
				],
				[
					'code' => 'handoff-unknown-mapping-field',
					'message' => 'Handoff "to-case": mapping field "colour" is not part of the "https://openregister.app/ns#Case" contract (fields: title, summary, channel, source, requester, priority, caseType).',
				],
				[
					'code' => 'handoff-bad-mapping-expression',
					'message' => 'Handoff "to-case": mapping for "summary" must use exactly one expression kind of [from, const, template, semanticRef, provenance]; offending key(s): none.',
				],
				[
					'code' => 'handoff-bad-mapping-expression',
					'message' => 'Handoff "to-case": mapping for "channel" must use exactly one expression kind of [from, const, template, semanticRef, provenance]; offending key(s): none.',
				],
				[
					'code' => 'handoff-bad-mapping-expression',
					'message' => 'Handoff "to-case": mapping for "requester" must use exactly one expression kind of [from, const, template, semanticRef, provenance]; offending key(s): weird, odd.',
				],
				[
					'code' => 'handoff-unknown-mapping-field',
					'message' => 'Handoff "to-case": mapping field "0" is not part of the "https://openregister.app/ns#Case" contract (fields: title, summary, channel, source, requester, priority, caseType).',
				],
				[
					'code' => 'handoff-missing-mandatory-field',
					'message' => 'Handoff "to-case": mandatory "https://openregister.app/ns#Case" contract field(s) not mapped: source.',
				],
			],
			'unavailable and success' => [
				[
					'code' => 'handoff-bad-when-unavailable',
					'message' => 'Handoff "u1": `whenUnavailable` must be one of [hide, queue].',
				],
				[
					'code' => 'handoff-bad-success-update',
					'message' => 'Handoff "u1": `onSuccess` must carry a non-empty `set` map.',
				],
				[
					'code' => 'handoff-bad-success-update',
					'message' => 'Handoff "u2": `onSuccess` must carry a non-empty `set` map.',
				],
				[
					'code' => 'handoff-bad-success-update',
					'message' => 'Handoff "u3": onSuccess.set names property "missing" which the source schema does not define.',
				],
				[
					'code' => 'handoff-bad-success-update',
					'message' => 'Handoff "u3": onSuccess.set names property "4" which the source schema does not define.',
				],
				[
					'code' => 'handoff-bad-success-update',
					'message' => 'Handoff "u4": `onSuccess` must carry a non-empty `set` map.',
				],
			],
			'properties scalar' => [
				[
					'code' => 'handoff-bad-success-update',
					'message' => 'Handoff "to-case": onSuccess.set names property "status" which the source schema does not define.',
				],
			],
		];
	}//end expectedErrors()
}//end class
