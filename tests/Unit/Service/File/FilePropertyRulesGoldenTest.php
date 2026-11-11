<?php

/**
 * FilePropertyRules golden test.
 *
 * Records what each FilePropertyRules method answers for a set of property and
 * file configurations, so a refactor of the rules can be shown to keep their
 * behaviour.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/file-actions/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\FilePropertyRules;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\File\FilePropertyRules
 */
class FilePropertyRulesGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/file-property-rules-golden.json';

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$out = [];
		foreach (array_keys(self::scenarios()) as $name) {
			$out[$name] = [$name];
		}

		return $out;
	}//end cases()

	/**
	 * Each scenario is one configuration, asked of all three methods.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		return [
			'empty' => [],
			'file-type' => ['type' => 'file'],
			'file-array' => ['type' => 'array', 'items' => ['type' => 'file']],
			'array-of-strings' => ['type' => 'array', 'items' => ['type' => 'string']],
			'array-items-not-array' => ['type' => 'array', 'items' => 'file'],
			'string-format-file' => ['type' => 'string', 'format' => 'file'],
			'bytes-int' => ['type' => 'file', 'maxSize' => 2048],
			'bytes-float' => ['type' => 'file', 'maxSize' => 10.9],
			'bytes-numeric-string' => ['type' => 'file', 'maxSize' => '512'],
			'bytes-decimal-string' => ['type' => 'file', 'maxSize' => '1.5'],
			'bytes-tiny' => ['type' => 'file', 'maxSize' => 0.4],
			'bytes-zero-mb-set' => ['type' => 'file', 'maxSize' => 0, 'fileConfiguration' => ['maxSize' => 2]],
			'bytes-negative-mb-set' => ['type' => 'file', 'maxSize' => -5, 'fileConfiguration' => ['maxSize' => '0.5']],
			'bytes-text-mb-float' => ['type' => 'file', 'maxSize' => 'big', 'fileConfiguration' => ['maxSize' => 1.25]],
			'mb-zero' => ['type' => 'file', 'fileConfiguration' => ['maxSize' => 0]],
			'mb-text' => ['type' => 'file', 'fileConfiguration' => ['maxSize' => 'ten']],
			'mb-tiny' => ['type' => 'file', 'fileConfiguration' => ['maxSize' => 0.0000001]],
			'mb-missing' => ['type' => 'file', 'fileConfiguration' => []],
			'editor-not-array' => ['type' => 'file', 'fileConfiguration' => 'big'],
			'editor-null' => ['type' => 'file', 'fileConfiguration' => null],
			'types-list' => ['type' => 'file', 'allowedTypes' => ['application/pdf', 'image/png']],
			'types-string' => ['type' => 'file', 'allowedTypes' => 'application/pdf'],
			'types-editor' => ['type' => 'file', 'fileConfiguration' => ['allowedMimeTypes' => ['image/jpeg'], 'maxSize' => 1]],
			'array-items-config' => ['type' => 'array', 'items' => ['type' => 'file', 'maxSize' => 99, 'allowedTypes' => ['text/plain']]],
		];
	}//end scenarios()

	/**
	 * @dataProvider cases
	 */
	public function testFilePropertyRulesMatchGolden(string $name): void {
		$recorded = $this->record(config: self::scenarios()[$name]);

		$all = [];
		if (file_exists(self::FIXTURE) === true) {
			$all = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$all[$name] = $recorded;
			ksort($all);
			file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $all);
		$this->assertNotEmpty($all[$name]);
		$this->assertSame($all[$name], $recorded);
	}//end testFilePropertyRulesMatchGolden()

	/**
	 * @param array<string, mixed> $config
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $config): array {
		$rules = new FilePropertyRules();
		return [
			'fileConfigOf' => $this->attempt(fn () => $rules->fileConfigOf($config)),
			'allowedTypes' => $this->attempt(fn () => $rules->allowedTypes($config)),
			'maxSizeBytes' => $this->attempt(fn () => $rules->maxSizeBytes($config)),
		];
	}//end record()

	/**
	 * @return array<string, mixed>
	 */
	private function attempt(callable $call): array {
		try {
			return ['returned' => $call()];
		} catch (Throwable $e) {
			return ['threw' => get_class($e) . ': ' . $e->getMessage()];
		}
	}//end attempt()
}//end class
