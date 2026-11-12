<?php

/**
 * Golden test for reading and shape-checking macro bindings on declared actions.
 *
 * Every combination of a `macro` and a `flow` value, plus malformed action
 * blocks, replayed against a recording. Record with UPDATE_GOLDEN=1.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\MacroActionBinding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\MacroActionBinding
 */
class MacroActionBindingGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/macro-action-binding-golden.json';

	/** @var array<string, mixed>|null */
	private static ?array $golden = null;

	/** @var array<string, mixed> */
	private static array $recorded = [];

	/**
	 * Schema configurations to read.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$macros = ['absent' => null, 'true' => true, 'false' => false, 'string' => 'yes', 'null' => 'NULL', 'int' => 1];
		$flows = ['absent' => null, 'name' => 'Flow A', 'padded' => '  Flow B  ', 'empty' => '', 'blank' => '   ', 'int' => 5, 'null' => 'NULL', 'array' => []];
		$cases = [];
		foreach ($macros as $m => $macro) {
			foreach ($flows as $f => $flow) {
				$definition = ['name' => 'Act'];
				if ($macro !== null) {
					$definition['macro'] = ($macro === 'NULL' ? null : $macro);
				}

				if ($flow !== null) {
					$definition['flow'] = ($flow === 'NULL' ? null : $flow);
				}

				$cases["macro $m, flow $f"] = [[MacroActionBinding::ACTION_BLOCK => ['act' => $definition]]];
			}
		}

		$cases['no block'] = [[]];
		$cases['block not an array'] = [[MacroActionBinding::ACTION_BLOCK => 'x']];
		$cases['odd keys and definitions'] = [[MacroActionBinding::ACTION_BLOCK => [
			3 => ['macro' => true, 'flow' => 'F'],
			'' => ['macro' => true, 'flow' => 'F'],
			'scalar' => 'F',
			'ok' => ['flow' => 'F'],
		]]];
		$cases['several actions'] = [[MacroActionBinding::ACTION_BLOCK => [
			'a' => ['macro' => 'x', 'flow' => ''],
			'b' => ['macro' => true],
			'c' => ['macro' => true, 'flow' => 'C'],
			'd' => ['macro' => false, 'flow' => 'D'],
			'e' => ['macro' => 2, 'flow' => 'E'],
		]]];

		return $cases;
	}

	/**
	 * @param array<string, mixed> $configuration The schema configuration.
	 */
	#[DataProvider('cases')]
	public function testReadingMatchesTheRecording(array $configuration): void {
		$bindings = array_map(
			static fn (MacroActionBinding $b): array => [$b->action, $b->flow],
			MacroActionBinding::parse(configuration: $configuration)
		);
		$actual = ['parse' => $bindings, 'refusals' => MacroActionBinding::refusals(configuration: $configuration)];

		$name = (string)$this->dataName();
		if (getenv('UPDATE_GOLDEN') === '1') {
			self::$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->assertTrue(true);
			return;
		}

		self::$golden ??= json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertArrayHasKey($name, self::$golden);
		$this->assertSame(self::$golden[$name], $actual);
	}
}
