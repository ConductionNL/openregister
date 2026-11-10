<?php

/**
 * Golden record of HealthCheckDescriptor::fromArray(): the descriptor built, or the refusal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Observability\HealthCheckDescriptor;
use OCA\OpenRegister\AppHost\Observability\ObservabilityValidationException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\AppHost\Observability\HealthCheckDescriptor
 * @uses \OCA\OpenRegister\AppHost\Observability\ObservabilityValidationException
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.1
 */
class HealthCheckDescriptorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/health-check-descriptor-golden.json';

	private static function entries(): array {
		return [
			'no type' => [],
			'unknown type' => ['type' => 'ping'],
			'type not a string' => ['type' => 5],
			'type true' => ['type' => true],
			'database defaults' => ['type' => 'database'],
			'bad severity' => ['type' => 'database', 'severity' => 'fatal'],
			'severity array' => ['type' => 'database', 'severity' => ['x']],
			'degraded' => ['type' => 'database', 'severity' => 'degraded', 'id' => 'db'],
			'empty id' => ['type' => 'database', 'id' => ''],
			'id not a string' => ['type' => 'database', 'id' => 3],
			'appEnabled' => ['type' => 'appEnabled', 'app' => 'Mail_2-x'],
			'appEnabled bad app' => ['type' => 'appEnabled', 'app' => 'mail app'],
			'appEnabled no app' => ['type' => 'appEnabled'],
			'appConfig' => ['type' => 'appConfig', 'key' => 'url'],
			'appConfig nonEmpty' => ['type' => 'appConfig', 'key' => 'url', 'assert' => 'nonEmpty'],
			'appConfig no key' => ['type' => 'appConfig'],
			'appConfig empty key' => ['type' => 'appConfig', 'key' => ''],
			'appConfig bad assert' => ['type' => 'appConfig', 'key' => 'k', 'assert' => 'exists'],
			'app ignored on other types' => ['type' => 'database', 'app' => 'x', 'key' => 'y', 'assert' => 'z'],
		];
	}//end entries()

	public function testFromArrayMatchesGolden(): void {
		$actual = [];
		foreach (array_merge(self::entries(), ['every type' => null]) as $name => $raw) {
			if ($raw === null) {
				foreach (HealthCheckDescriptor::TYPES as $i => $type) {
					$actual['type ' . $type] = $this->outcome(raw: ['type' => $type, 'app' => 'a', 'key' => 'k'], index: $i);
				}

				continue;
			}

			$actual[$name] = $this->outcome(raw: $raw, index: 4);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);
	}//end testFromArrayMatchesGolden()

	private function outcome(array $raw, int $index): array {
		try {
			$d = HealthCheckDescriptor::fromArray($raw, $index);
			return ['id' => $d->id, 'type' => $d->type, 'severity' => $d->severity, 'app' => $d->app, 'key' => $d->key, 'assert' => $d->assert];
		} catch (ObservabilityValidationException $e) {
			return ['refused' => $e->getMessage()];
		}
	}//end outcome()
}//end class
