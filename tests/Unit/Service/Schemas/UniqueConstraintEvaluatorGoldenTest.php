<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Schemas;

use OCA\OpenRegister\Service\Schemas\UniqueConflict;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each case calls one public method; the result is compared with
 * fixtures/unique-constraint-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class UniqueConstraintEvaluatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/unique-constraint-golden.json';

	/**
	 * @return array<string, array{0: string, 1: array<int, mixed>}>
	 */
	public static function cases(): array {
		$pair = ['name' => 'pair', 'properties' => ['a', 'b'], 'action' => 'refuse'];
		return [
			'constraints null' => ['constraints', [null, false]],
			'constraints null legacy' => ['constraints', [null, true]],
			'constraints empty' => ['constraints', [[], true]],
			'constraints legacy ignored' => ['constraints', [['unique' => 'code'], false]],
			'constraints legacy string' => ['constraints', [['unique' => ' code '], true]],
			'constraints legacy list' => ['constraints', [['unique' => ['a', ' ', 'b', 5]], true]],
			'constraints legacy blank' => ['constraints', [['unique' => '  '], true]],
			'constraints legacy number' => ['constraints', [['unique' => 7], true]],
			'constraints declared scalar' => ['constraints', [['uniqueConstraints' => 'x'], true]],
			'constraints legacy and declared' => ['constraints', [['unique' => 'code', 'uniqueConstraints' => [['properties' => 'email']]], true]],
			'constraints named keys' => ['constraints', [['uniqueConstraints' => ['byMail' => ['properties' => ['email']], 'own' => ['name' => ' Own ', 'properties' => 'x', 'action' => ' REPORT ']]], false]],
			'constraints malformed dropped' => ['constraints', [['uniqueConstraints' => ['s', ['properties' => []], ['properties' => 'a', 'action' => 'warn'], ['properties' => ['', 3]], ['properties' => ['a', 'b'], 'action' => 'refuse']]], false]],
			'constraints blank name' => ['constraints', [['uniqueConstraints' => [['name' => '  ', 'properties' => ['a', 'b']]]], false]],
			'constraints null action' => ['constraints', [['uniqueConstraints' => [['properties' => 'a', 'action' => null]]], false]],
			'filters complete' => ['filtersFor', [$pair, ['a' => 1, 'b' => 'x', 'c' => 3]]],
			'filters missing' => ['filtersFor', [$pair, ['a' => 1]]],
			'filters null value' => ['filtersFor', [$pair, ['a' => 1, 'b' => null]]],
			'filters empty string' => ['filtersFor', [$pair, ['a' => '', 'b' => 2]]],
			'filters zero and false' => ['filtersFor', [$pair, ['a' => 0, 'b' => false]]],
			'filters array value' => ['filtersFor', [$pair, ['a' => [1], 'b' => ['k' => 'v']]]],
			'filters no properties' => ['filtersFor', [['name' => 'n', 'properties' => [], 'action' => 'refuse'], ['a' => 1]]],
			'describe with id' => ['describeBreach', [$pair, new UniqueConflict(['a' => 1, 'b' => 'x'], 'uuid-1')]],
			'describe null id' => ['describeBreach', [$pair, new UniqueConflict(['a' => true, 'b' => false], null)]],
			'describe empty id' => ['describeBreach', [$pair, new UniqueConflict(['a' => 1.5], '')]],
			'describe structures' => ['describeBreach', [$pair, new UniqueConflict(['a' => ['x' => 1], 'b' => [1, 2]], 'u')]],
			'describe single' => ['describeBreach', [['name' => 'n', 'properties' => ['code'], 'action' => 'report'], new UniqueConflict(['code' => 'ABC'], 'z')]],
		];
	}//end cases()

	/**
	 * @param array<int, mixed> $arguments
	 */
	#[DataProvider('cases')]
	public function testMatchesGolden(string $method, array $arguments): void {
		$actual = json_decode(json_encode(['result' => (new UniqueConstraintEvaluator())->$method(...$arguments)]), true);
		$name = $this->dataName();
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
}//end class
