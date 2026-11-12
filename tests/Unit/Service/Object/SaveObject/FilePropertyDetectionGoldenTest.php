<?php

/**
 * Golden record of FilePropertyHandler::isFileProperty() over schema-based and value-based detection.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler
 * @uses \OCA\OpenRegister\Db\Schema
 *
 * @spec openspec/specs/content-versioning/spec.md
 */
class FilePropertyDetectionGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/file-property-detection-golden.json';

	/**
	 * Value-based cases: name => value.
	 *
	 * @return array<string, mixed>
	 */
	private static function values(): array {
		$b64 = base64_encode(str_repeat('binary-ish payload ', 8));
		return [
			'null' => null,
			'int' => 5,
			'empty string' => '',
			'data uri' => 'data:image/png;base64,AAAA',
			'data uri not at start' => 'x data:image/png',
			'pdf url' => 'https://example.org/files/report.PDF',
			'http jpg url' => 'http://example.org/a.jpg',
			'page url' => 'https://example.org/about',
			'url unknown extension' => 'https://example.org/a.xyz123',
			'url without path' => 'https://example.org',
			'ftp url' => 'ftp://example.org/a.pdf',
			'long base64' => $b64,
			'short base64' => base64_encode('short'),
			'long non-base64' => str_repeat('not base64!', 12),
			'file object' => ['id' => 1, 'title' => 'a.pdf', 'size' => 3],
			'file object with path only' => ['id' => 1, 'path' => '/a.pdf', 'extension' => 'pdf'],
			'id without title or path' => ['id' => 1, 'size' => 3],
			'empty array' => [],
			'list with data uri' => ['x', 'data:text/plain,hi'],
			'list with pdf url' => ['https://example.org/a.pdf'],
			'list with page url' => ['https://example.org/about'],
			'list with base64' => [$b64],
			'list with file object' => [['id' => 2, 'title' => 't', 'downloadUrl' => 'u']],
			'list with plain arrays' => [['a' => 1], 'text'],
			'list of ints' => [1, 2],
		];
	}//end values()

	/**
	 * Schema-based cases: name => [properties, propertyName].
	 *
	 * @return array<string, array{0: array, 1: string}>
	 */
	private static function schemaCases(): array {
		return [
			'schema file' => [['doc' => ['type' => 'file']], 'doc'],
			'schema array of files' => [['docs' => ['type' => 'array', 'items' => ['type' => 'file']]], 'docs'],
			'schema array of strings' => [['tags' => ['type' => 'array', 'items' => ['type' => 'string']]], 'tags'],
			'schema array without items' => [['tags' => ['type' => 'array']], 'tags'],
			'schema string' => [['name' => ['type' => 'string']], 'name'],
			'schema no type' => [['name' => []], 'name'],
			'schema missing property' => [['name' => ['type' => 'file']], 'other'],
		];
	}//end schemaCases()

	public function testDetectionMatchesGolden(): void {
		$handler = new FilePropertyHandler($this->createMock(LoggerInterface::class), $this->createMock(FileService::class));
		$actual = [];
		foreach (self::values() as $name => $value) {
			$actual['value: ' . $name] = $handler->isFileValue($value);
		}

		foreach (self::schemaCases() as $name => [$properties, $property]) {
			$schema = new Schema();
			$schema->setProperties($properties);
			// The value is a data URI so a schema answer cannot fall through to value detection unnoticed.
			$actual['schema: ' . $name] = $handler->isFileInSchema($schema, $property);
			$actual['schema without name: ' . $name] = $handler->isFileValue('plain');
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			ksort($actual);
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		ksort($actual);
		$this->assertSame($golden, $actual);
	}//end testDetectionMatchesGolden()
}//end class
