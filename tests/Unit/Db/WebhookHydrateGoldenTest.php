<?php

/**
 * Golden record of Webhook::hydrate(): the serialised entity and the fields it marks updated.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Webhook
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */
class WebhookHydrateGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/webhook-hydrate-golden.json';

	public static function cases(): array {
		$cases = [
			'empty' => [],
			'every field as arrays' => [
				'id' => 4, 'uuid' => 'w-1', 'name' => 'Hook', 'url' => 'https://example.org/h', 'method' => 'PUT',
				'events' => ['object.created', 'object.updated'], 'headers' => ['X-A' => '1'], 'secret' => 's',
				'enabled' => 1, 'organisation' => 'org-1', 'filters' => ['register' => 2], 'retryPolicy' => 'linear',
				'maxRetries' => '5', 'timeout' => '45', 'configuration' => ['useCloudEvents' => true], 'mapping' => '9',
			],
			'json strings' => [
				'events' => '["a"]', 'headers' => '{"X":"y"}', 'filters' => '{}', 'configuration' => '{"k":1}',
			],
			'nulls are skipped' => ['name' => null, 'events' => null, 'enabled' => null, 'timeout' => null],
			'falsy values kept' => ['enabled' => false, 'maxRetries' => 0, 'name' => '', 'events' => []],
			'enabled string' => ['enabled' => '0'],
			'numeric strings equal to the defaults' => ['timeout' => '30', 'maxRetries' => '3', 'enabled' => 1],
			'mapping null clears' => ['mapping' => null],
			'mapping empty clears' => ['mapping' => ''],
			'mapping zero' => ['mapping' => 0],
			'unknown key ignored' => ['nope' => 1, 'name' => 'n'],
			'mapping null clears a set mapping' => ['mapping' => null, '_before' => ['mapping' => 7]],
			'mapping left out keeps a set mapping' => ['name' => 'n', '_before' => ['mapping' => 7]],
		];

		$out = [];
		foreach ($cases as $name => $case) {
			$out[$name] = [$case];
		}

		return $out;
	}//end cases()

	#[DataProvider('cases')]
	public function testHydrateMatchesGolden(array $input): void {
		$webhook = new Webhook();
		$webhook->hydrate($input['_before'] ?? []);
		unset($input['_before']);
		$webhook->resetUpdatedFields();
		$returned = $webhook->hydrate($input);

		$row = $webhook->jsonSerialize();
		unset($row['created'], $row['updated']);
		$actual = json_decode((string)json_encode(['row' => $row, 'updated' => array_keys($webhook->getUpdatedFields()), 'same' => $returned === $webhook]), true);

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
	}//end testHydrateMatchesGolden()
}//end class
