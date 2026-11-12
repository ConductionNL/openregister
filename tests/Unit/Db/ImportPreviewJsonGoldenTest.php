<?php

/**
 * Golden test for the ImportPreview entity: field types and serialisation.
 *
 * Pins the field types the constructor registers and the exact
 * jsonSerialize() output (keys, order, nesting, values, PHP types) for an
 * empty preview, a preview hydrated from database strings, and one whose JSON
 * columns hold empty values, plus isCommittable() per state. Dates carry an
 * explicit offset so nothing depends on the PHP build's default timezone.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ImportPreview;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ImportPreview
 */
class ImportPreviewJsonGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/import-preview-json-golden.json';

	public function testFieldTypesAndJsonMatchGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $preview) {
			$actual[$name] = [
				'types' => $preview->getFieldTypes(),
				'json' => $this->typed(value: $preview->jsonSerialize()),
				'committable' => $preview->isCommittable(),
			];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertNotEmpty($expected);
		foreach ($expected as $name => $outcome) {
			$this->assertNotEmpty($outcome['json'], $name);
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testFieldTypesAndJsonMatchGolden()

	/**
	 * @return array<string, ImportPreview>
	 */
	private function cases(): array {
		$cases = [
			'empty' => new ImportPreview(),
			'full' => ImportPreview::fromRow($this->row()),
			'empty json columns' => ImportPreview::fromRow(['id' => 2, 'match_key' => '[]', 'report' => '{}', 'state' => 'previewing']),
		];
		foreach (['previewed', 'committing', 'committed', 'failed', 'Previewed', ''] as $state) {
			$cases['state ' . var_export($state, true)] = ImportPreview::fromRow(['id' => 3, 'state' => $state]);
		}

		return $cases;
	}//end cases()

	private function row(): array {
		return [
			'id' => 1,
			'uuid' => 'prev-1',
			'register_id' => '3',
			'schema_id' => '4',
			'pack_slug' => 'pack',
			'policy' => 'upsert',
			'match_key' => '["code","name"]',
			'source_name' => 'data.csv',
			'source_format' => 'csv',
			'source_hash' => 'abc',
			'source_path' => '/tmp/x',
			'state' => 'previewed',
			'total' => '10',
			'processed' => '9',
			'to_create' => '4',
			'to_update' => '3',
			'to_skip' => '1',
			'to_refuse' => '1',
			'applied' => '0',
			'failed' => '0',
			'report' => '{"rows":[{"line":2,"reason":"dup"}]}',
			'created_by' => 'alice',
			'created' => '2001-02-03T04:05:06+00:00',
			'updated' => '2001-02-03T05:05:06+01:00',
		];
	}//end row()

	/**
	 * Values with their PHP type, so `'3'` and `3` do not pin the same.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The value, each scalar as `type:value`.
	 */
	private function typed(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->typed(value: $item), $value);
		}

		return get_debug_type($value) . ':' . var_export($value, true);
	}//end typed()
}//end class
