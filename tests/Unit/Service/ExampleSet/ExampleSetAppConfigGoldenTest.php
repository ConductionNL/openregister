<?php

/**
 * Golden test for writing, proving and restoring an example set's app config.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins every public answer of ExampleSetAppConfig, with the config calls it
 * makes and the store it leaves, over an in-memory app config. Compared with
 * fixtures/example-set-app-config-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 */
class ExampleSetAppConfigGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/example-set-app-config-golden.json';

	/**
	 * Values by "app/key"; ints are kept as int.
	 *
	 * @var array<string, string|int>
	 */
	private array $store = [];

	/**
	 * Calls made to the app config, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	private int $generated = 0;

	public function testEveryAnswerMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->seed();
			$this->calls = [];
			$service = $this->service();
			try {
				$result = $case($service);
			} catch (\Throwable $exception) {
				$result = ['threw' => $exception::class, 'message' => $exception->getMessage()];
			}

			$actual[$name] = ['result' => $result, 'calls' => $this->calls, 'store' => $this->store];
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, $json);
		}

		$this->assertFileExists(self::FIXTURE);
		$this->assertSame(file_get_contents(self::FIXTURE), $json);
	}//end testEveryAnswerMatchesTheGolden()

	/**
	 * The starting store for every case.
	 *
	 * Keys: `a/text` "old", `a/empty` "", `a/count` 5 (int), `a/secret` (sensitive),
	 * `a/boom` (every call throws), `a/gen` the value generated as GEN-1.
	 *
	 * @return void
	 */
	private function seed(): void {
		$this->generated = 0;
		$this->store = ['a/text' => 'old', 'a/empty' => '', 'a/count' => 5, 'a/secret' => 's3cret', 'a/gen' => 'GEN-1'];
	}//end seed()

	/**
	 * Case name => call.
	 *
	 * @return array<string, callable(ExampleSetAppConfig): mixed>
	 */
	private function cases(): array {
		$genPrint = hash('sha256', 'GEN-1');
		$entry = static fn (array $over): array => array_merge(['app' => 'a', 'key' => 'text', 'had' => true, 'old' => 'old', 'new' => 'new', 'generated' => false, 'type' => 'string'], $over);

		$cases = [];
		foreach ([
			'refusal plain' => ['a', 'text', false],
			'refusal sensitive' => ['a', 'secret', false],
			'refusal sensitive generated' => ['a', 'secret', true],
			'refusal missing' => ['a', 'nope', false],
			'refusal throws' => ['a', 'boom', false],
		] as $name => [$app, $key, $generate]) {
			$cases[$name] = static fn (ExampleSetAppConfig $s) => $s->refusal($app, $key, $generate);
		}

		foreach ([
			'apply new string' => ['a', 'fresh', 'v', false],
			'apply same string' => ['a', 'text', 'old', false],
			'apply other string' => ['a', 'text', 'new', false],
			'apply empty to empty' => ['a', 'empty', '', false],
			'apply generate new' => ['a', 'fresh', 'ignored', true],
			'apply generate over empty' => ['a', 'empty', '', true],
			'apply generate existing' => ['a', 'text', '', true],
			'apply int new' => ['a', 'n', '7', false, 'int'],
			'apply int same' => ['a', 'count', '5', false, 'int'],
			'apply int other' => ['a', 'count', '9', false, 'int'],
			'apply floor above' => ['a', 'count', '3', false, 'int', true],
			'apply floor equal' => ['a', 'count', '5', false, 'int', true],
			'apply floor below' => ['a', 'count', '8', false, 'int', true],
			'apply floor missing' => ['a', 'n', '8', false, 'int', true],
			'apply floor string type' => ['a', 'text', '2', false, 'string', true],
			'apply throws' => ['a', 'boom', 'x', false],
			'apply generate throws' => ['a', 'boom', '', true],
		] as $name => $args) {
			$cases[$name] = static fn (ExampleSetAppConfig $s) => $s->apply(...$args);
		}

		foreach ([
			'raise plain to floor' => [$entry(['key' => 'count', 'old' => '1', 'new' => '5', 'type' => 'int']), '8'],
			'raise floor lower' => [$entry(['key' => 'count', 'new' => '3', 'type' => 'int', 'atLeast' => true]), '8'],
			'raise floor already' => [$entry(['key' => 'count', 'new' => '9', 'type' => 'int', 'atLeast' => true]), '8'],
			'raise floor equal' => [$entry(['key' => 'count', 'new' => '8', 'type' => 'int', 'atLeast' => true]), '8'],
			'raise key above' => [$entry(['key' => 'count', 'new' => '1', 'type' => 'int']), '4'],
			'raise key missing' => [$entry(['key' => 'n', 'new' => '1', 'type' => 'int']), '4'],
			'raise throws' => [$entry(['key' => 'boom', 'new' => '1']), '4'],
			'raise floor false flag' => [$entry(['key' => 'count', 'new' => '9', 'atLeast' => false]), '8'],
		] as $name => [$given, $value]) {
			$cases[$name] = static fn (ExampleSetAppConfig $s) => $s->raise($given, $value);
		}

		foreach ([
			'lost written kept' => $entry(['new' => 'old']),
			'lost written changed' => $entry([]),
			'lost written missing' => $entry(['key' => 'nope', 'new' => '']),
			'lost int kept' => $entry(['key' => 'count', 'new' => '5', 'type' => 'int']),
			'lost int legacy untyped' => $entry(['key' => 'count', 'new' => '5', 'type' => null]),
			'lost generated kept' => $entry(['key' => 'gen', 'generated' => true]),
			'lost generated gone' => $entry(['key' => 'nope', 'generated' => true]),
			'lost floor kept' => $entry(['key' => 'count', 'new' => '4', 'type' => 'int', 'atLeast' => true]),
			'lost floor below' => $entry(['key' => 'count', 'new' => '6', 'type' => 'int', 'atLeast' => true]),
			'lost floor missing' => $entry(['key' => 'n', 'new' => '1', 'type' => 'int', 'atLeast' => true]),
			'lost throws' => $entry(['key' => 'boom']),
		] as $name => $given) {
			$cases[$name] = static fn (ExampleSetAppConfig $s) => $s->lost($given);
		}

		foreach ([
			'revert written restores old' => $entry(['new' => 'old', 'old' => 'before']),
			'revert written changed since' => $entry(['new' => 'mine']),
			'revert not had deletes' => $entry(['had' => false, 'new' => 'old']),
			'revert key gone' => $entry(['key' => 'nope', 'had' => true, 'old' => 'x']),
			'revert key gone not had' => $entry(['key' => 'nope', 'had' => false]),
			'revert int' => $entry(['key' => 'count', 'new' => '5', 'old' => '2', 'type' => 'int']),
			'revert generated with print' => $entry(['key' => 'gen', 'generated' => true, 'old' => '', 'new' => '', 'fingerprint' => $genPrint]),
			'revert generated not had' => $entry(['key' => 'gen', 'had' => false, 'generated' => true, 'old' => '', 'new' => '', 'fingerprint' => $genPrint]),
			'revert generated replaced' => $entry(['key' => 'gen', 'generated' => true, 'old' => '', 'new' => '', 'fingerprint' => hash('sha256', 'other')]),
			'revert generated legacy' => $entry(['key' => 'gen', 'generated' => true, 'old' => 'x', 'new' => '']),
			'revert throws' => $entry(['key' => 'boom']),
			'revert delete throws' => $entry(['key' => 'boomdel', 'had' => false, 'new' => 'old']),
		] as $name => $given) {
			$cases[$name] = static fn (ExampleSetAppConfig $s) => $s->revert($given);
		}

		return $cases;
	}//end cases()

	private function service(): ExampleSetAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$record = function (string $method, array $args): void {
			$this->calls[] = array_merge([$method], $args);
			if (($args[1] ?? '') === 'boom') {
				throw new RuntimeException($method . ' failed');
			}
		};
		$config->method('hasKey')->willReturnCallback(
			function (string $app, string $key) use ($record): bool {
				$record('hasKey', [$app, $key]);
				return array_key_exists($app . '/' . $key, $this->store) || $key === 'boomdel';
			}
		);
		$config->method('isSensitive')->willReturnCallback(
			function (string $app, string $key) use ($record): bool {
				$record('isSensitive', [$app, $key]);
				return $key === 'secret';
			}
		);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) use ($record): string {
				$record('getValueString', [$app, $key, $default]);
				return (string) ($this->store[$app . '/' . $key] ?? ($key === 'boomdel' ? 'old' : $default));
			}
		);
		$config->method('getValueInt')->willReturnCallback(
			function (string $app, string $key) use ($record): int {
				$record('getValueInt', [$app, $key]);
				return (int) ($this->store[$app . '/' . $key] ?? 0);
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, ...$rest) use ($record): bool {
				$record('setValueString', array_merge([$app, $key, $value], $rest));
				$this->store[$app . '/' . $key] = $value;
				return true;
			}
		);
		$config->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value) use ($record): bool {
				$record('setValueInt', [$app, $key, $value]);
				$this->store[$app . '/' . $key] = $value;
				return true;
			}
		);
		$config->method('deleteKey')->willReturnCallback(
			function (string $app, string $key) use ($record): void {
				$record('deleteKey', [$app, $key]);
				if ($key === 'boomdel') {
					throw new RuntimeException('deleteKey failed');
				}

				unset($this->store[$app . '/' . $key]);
			}
		);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			function (int $length, string $chars): string {
				$this->calls[] = ['generate', $length, $chars === ISecureRandom::CHAR_ALPHANUMERIC];
				$this->generated++;
				return 'GEN-' . (10 + $this->generated);
			}
		);

		return new ExampleSetAppConfig(appConfig: $config, random: $random);
	}//end service()
}//end class
