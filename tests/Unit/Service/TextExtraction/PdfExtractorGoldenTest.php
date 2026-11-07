<?php

/**
 * PdfExtractor golden test.
 *
 * Records what PdfExtractor::extract() returns, throws and logs for a set of
 * text-layer outcomes, so a refactor of the extractor can be shown to keep its
 * behaviour.
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
 * @spec openspec/specs/text-extraction/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use Error;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCP\Files\File;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor
 */
class PdfExtractorGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/pdf-extractor-golden.json';

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
	 * `text` scripts what the text layer returns; `real` runs the real parser on the bytes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		return [
			'text' => ['text' => 'Hello PDF'],
			'text-whitespace' => ['text' => "  \n "],
			'text-zero' => ['text' => '0'],
			'empty' => ['text' => ''],
			'parser-throws' => ['text' => 'exception'],
			'parser-errors' => ['text' => 'error'],
			'content-refused' => ['text' => 'Hello', 'content' => 'refused'],
			'real-garbage' => ['real' => 'not a pdf at all'],
			'real-empty' => ['real' => ''],
		];
	}//end scenarios()

	/**
	 * @dataProvider cases
	 */
	public function testPdfExtractorMatchesGolden(string $name): void {
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
		$this->assertNotEmpty($all[$name]['log']);
		$this->assertSame($all[$name], $recorded);
	}//end testPdfExtractorMatchesGolden()

	/**
	 * @param array<string, mixed> $scenario
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $scenario): array {
		$lines = [];
		$logger = new class($lines) extends AbstractLogger {
			/**
			 * @param array<int, string> $lines
			 */
			public function __construct(private array &$lines) {
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				unset($context['line']);
				$context['file'] = basename((string)($context['file'] ?? ''));
				$this->lines[] = $level . ': ' . $message . ' ' . json_encode($context);
			}
		};

		$extractor = $this->extractor(logger: $logger, scenario: $scenario);
		try {
			$result = ['returned' => $extractor->extract($this->file(scenario: $scenario))];
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e) . ': ' . $e->getMessage()];
		}

		return ['result' => $result, 'log' => $lines];
	}//end record()

	/**
	 * @param array<string, mixed> $scenario
	 */
	private function extractor(LoggerInterface $logger, array $scenario): PdfExtractor {
		if (isset($scenario['real']) === true) {
			return new PdfExtractor($logger);
		}

		return new class($logger, $scenario['text']) extends PdfExtractor {
			public function __construct(LoggerInterface $logger, private string $script) {
				parent::__construct($logger);
			}

			public function extractText(string $pdfBytes): string {
				if ($this->script === 'exception') {
					throw new RuntimeException('Secured pdf file are currently not supported.');
				}

				if ($this->script === 'error') {
					throw new Error('parser crashed');
				}

				return $this->script;
			}
		};
	}//end extractor()

	/**
	 * @param array<string, mixed> $scenario
	 */
	private function file(array $scenario): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn('report.pdf');
		if (($scenario['content'] ?? null) === 'refused') {
			$file->method('getContent')->willThrowException(new NotPermittedException('cannot read'));
			return $file;
		}

		$file->method('getContent')->willReturn($scenario['real'] ?? '%PDF-scripted');
		return $file;
	}//end file()
}//end class
