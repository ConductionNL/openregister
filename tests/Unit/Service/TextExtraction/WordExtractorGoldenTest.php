<?php

/**
 * WordExtractor golden test.
 *
 * Records which reader WordExtractor picks for a set of MIME types and names,
 * and what extract() returns and logs on the paths that do not depend on the
 * PHP build (legacy .doc handling and unreadable files), so a refactor of the
 * extractor can be shown to keep its behaviour.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
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
 * @spec openspec/specs/text-extraction-word/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use OCA\OpenRegister\Files\Service\File\Office\ConversionManagerConverter;
use OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor;
use OCP\Files\File;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor
 */
class WordExtractorGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/word-extractor-golden.json';

	private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

	/**
	 * Calls made against the converter, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

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
	 * `resolve` asks for the reader only; the others run extract().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		return [
			'resolve' => [
				'resolve' => [
					[self::DOCX, 'a.bin'],
					['application/msword', 'a.docx'],
					['application/vnd.oasis.opendocument.text', 'a.doc'],
					['APPLICATION/MSWORD', 'a.txt'],
					['application/octet-stream', 'report.DOCX'],
					['application/octet-stream', 'report.Doc'],
					['application/octet-stream', 'report.odt'],
					['application/octet-stream', 'report.tar.odt'],
					['application/octet-stream', 'report.pdf'],
					['', ''],
					['', '.doc'],
					['text/plain', 'noextension'],
				],
			],
			'doc-without-converter' => ['mime' => 'application/msword', 'name' => 'old.doc'],
			'doc-converter-no-target' => ['mime' => 'application/msword', 'name' => 'old.doc', 'target' => null],
			'doc-by-extension-no-target' => ['mime' => 'application/octet-stream', 'name' => 'old.DOC', 'target' => null],
			'doc-to-text' => ['mime' => 'application/msword', 'name' => 'old.doc', 'target' => 'txt', 'converted' => 'Converted text'],
			'doc-to-blank-text' => ['mime' => 'application/msword', 'name' => 'old.doc', 'target' => 'txt', 'converted' => " \n\t "],
			'doc-to-zero' => ['mime' => 'application/msword', 'name' => 'old.doc', 'target' => 'txt', 'converted' => '0'],
			'doc-convert-fails' => ['mime' => 'application/msword', 'name' => 'old.doc', 'target' => 'txt', 'converted' => 'throw'],
			'doc-content-refused' => ['mime' => 'application/msword', 'name' => 'old.doc', 'target' => 'txt', 'content' => 'refused'],
			'docx-content-refused' => ['mime' => self::DOCX, 'name' => 'new.docx', 'content' => 'refused'],
			'odt-content-refused' => ['mime' => 'application/vnd.oasis.opendocument.text', 'name' => 'new.odt', 'content' => 'refused', 'target' => 'txt'],
		];
	}//end scenarios()

	/**
	 * @dataProvider cases
	 */
	public function testWordExtractorMatchesGolden(string $name): void {
		$recorded = $this->record(scenario: self::scenarios()[$name]);

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
		$this->assertNotEmpty($all[$name]['result']);
		$this->assertSame($all[$name], $recorded);
	}//end testWordExtractorMatchesGolden()

	/**
	 * @param array<string, mixed> $scenario
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $scenario): array {
		$this->calls = [];
		$lines = [];
		$logger = new class($lines) extends AbstractLogger {
			/**
			 * @param array<int, string> $lines
			 */
			public function __construct(private array &$lines) {
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = $level . ': ' . $message . ' ' . json_encode($context);
			}
		};

		$converter = null;
		if (array_key_exists('target', $scenario) === true) {
			$converter = $this->converter(scenario: $scenario);
		}

		$extractor = new WordExtractor($logger, $converter);
		try {
			$result = ['returned' => $this->invoke(extractor: $extractor, scenario: $scenario)];
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e) . ': ' . $e->getMessage()];
		}

		return ['result' => $result, 'calls' => $this->calls, 'log' => $lines];
	}//end record()

	/**
	 * @param array<string, mixed> $scenario
	 */
	private function invoke(WordExtractor $extractor, array $scenario): mixed {
		if (isset($scenario['resolve']) === true) {
			$resolve = new ReflectionMethod(WordExtractor::class, 'resolveWordReader');
			$out = [];
			foreach ($scenario['resolve'] as [$mime, $name]) {
				$out[] = $mime . ' | ' . $name . ' => ' . $resolve->invoke($extractor, $mime, $name);
			}

			return $out;
		}

		return $extractor->extract($this->file(scenario: $scenario));
	}//end invoke()

	/**
	 * @param array<string, mixed> $scenario
	 */
	private function converter(array $scenario): ConversionManagerConverter {
		$converter = $this->createMock(ConversionManagerConverter::class);
		$converter->method('legacyDocTarget')->willReturnCallback(
			function () use ($scenario): ?string {
				$this->calls[] = 'legacyDocTarget()';
				return $scenario['target'];
			}
		);
		$converter->method('convertLegacyDoc')->willReturnCallback(
			function (string $docBytes, string $target) use ($scenario): string {
				$this->calls[] = 'convertLegacyDoc(' . $docBytes . ', ' . $target . ')';
				if (($scenario['converted'] ?? null) === 'throw') {
					throw new RuntimeException('office server down');
				}

				return (string)($scenario['converted'] ?? '');
			}
		);

		return $converter;
	}//end converter()

	/**
	 * @param array<string, mixed> $scenario
	 */
	private function file(array $scenario): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(7);
		$file->method('getMimeType')->willReturn($scenario['mime']);
		$file->method('getName')->willReturn($scenario['name']);
		if (($scenario['content'] ?? null) === 'refused') {
			$file->method('getContent')->willThrowException(new NotPermittedException('cannot read'));
			return $file;
		}

		$file->method('getContent')->willReturn('doc-bytes');
		return $file;
	}//end file()
}//end class
