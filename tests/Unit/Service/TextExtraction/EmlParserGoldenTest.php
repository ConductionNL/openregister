<?php

/**
 * EmlParser golden test.
 *
 * Records the structured parse, the flat text and the log lines of EmlParser
 * for a set of messages, so a refactor of the parser can be shown to keep its
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
 * @spec openspec/specs/text-extraction-eml/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use OCA\OpenRegister\Files\Service\TextExtraction\EmlBody;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlStructure;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Throwable;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\EmlParser
 */
class EmlParserGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/eml-parser-golden.json';

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
	 * Each scenario is either an EML source (`eml`), a hand-built structure (`structure`) or a file (`file`).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$plain = "Content-Type: text/plain; charset=utf-8\r\n\r\nBody text.\r\n";
		return [
			'headers-only-subject' => ['eml' => "Subject: Hello\r\n" . $plain],
			'headers-from-only' => ['eml' => "From: Alice <alice@example.com>\r\n" . $plain],
			'headers-with-date' => ['eml' => "Subject: d\r\nDate: Mon, 11 May 2026 14:00:00 +0200\r\n" . $plain],
			'headers-to-list' => ['eml' => "To: Bob <bob@example.com>\r\n" . $plain],
			'date-rfc822-short-year' => ['eml' => "Date: Mon, 11 May 26 14:00:00 +0200\r\n" . $plain, 'flatten' => false],
			'date-with-zone-comment' => ['eml' => "Date: Mon, 11 May 2026 14:00:00 +0200 (CEST)\r\n" . $plain, 'flatten' => false],
			'date-without-day' => ['eml' => "Date: 11 May 2026 14:00:00 +0200\r\n" . $plain, 'flatten' => false],
			'date-iso' => ['eml' => "Date: 2026-05-11T14:00:00+02:00\r\n" . $plain, 'flatten' => false],
			'date-garbage' => ['eml' => "Date: not a date at all\r\n" . $plain],
			'address-lists' => [
				'eml' => "To: \"Doe, John\" <john@example.com>, jane@example.com,, \"Esc \\\"q, x\\\"\" <e@x.org>\r\n"
					. "Cc: <a,b@example.com>, plain\r\n" . $plain,
				'flatten' => false,
			],
			'address-trailing-comma' => ['eml' => "Cc: one@example.com ,\r\n" . $plain, 'flatten' => false],
			'address-unclosed-quote' => ['eml' => "To: \"open, quote <q@example.com>\r\n" . $plain, 'flatten' => false],
			'html-only' => [
				'eml' => "Subject: h\r\nContent-Type: text/html; charset=utf-8\r\n\r\n"
					. "<style>p{}</style><h1>Title</h1><p>Hello&nbsp;<b>Bob</b> &amp; co</p>\n\n\n\n<div>x\t\t y</div><script>alert(1)</script>\r\n",
			],
			'alternative' => [
				'eml' => "Subject: alt\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"b1\"\r\n\r\n"
					. "--b1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nplain part\r\n"
					. "--b1\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>html part</p>\r\n--b1--\r\n",
			],
			'empty-body' => ['eml' => "Subject: empty\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n"],
			'attachments' => ['eml' => self::withAttachments()],
			'nested' => ['eml' => self::nested(levels: 1)],
			'nested-depth-limit' => ['eml' => self::nested(levels: 5)],
			'nested-start-depth' => ['eml' => self::nested(levels: 2), 'depth' => 3],
			'nested-empty' => ['eml' => self::mixed(parts: ["Content-Type: message/rfc822\r\nContent-Disposition: attachment; filename=\"empty.eml\"\r\n\r\n"])],
			'built-full' => [
				'structure' => [
					'headers' => ['from' => 'a@b', 'to' => [], 'cc' => '', 'subject' => 'S', 'date' => null, 'extra' => 'x'],
					'plain' => "caf\xE9 cr\xE8me",
					'html' => '<p>ignored</p>',
				],
			],
			'built-string-date-and-list' => [
				'structure' => [
					'headers' => ['date' => 'yesterday-ish', 'to' => ['x@y', 'z@w'], 'subject' => '0'],
					'plain' => '',
					'html' => "<p>caf\xE9</p>",
				],
			],
			'built-empty' => ['structure' => ['headers' => [], 'plain' => null, 'html' => null]],
			'split-quoted-commas' => ['split' => '"Doe, John" <john@example.com>, jane@example.com,, "Esc \\"q, x\\"" <e@x.org>'],
			'split-angle-commas' => ['split' => '<a,b@example.com> , plain ,'],
			'split-unclosed-quote' => ['split' => '"open, quote <q@example.com>, b@c'],
			'split-quote-in-angle' => ['split' => '<a"b,c@d>, e'],
			'split-trailing-backslash' => ['split' => '"x\\'],
			'split-backslash-outside-quote' => ['split' => 'a\\,b, c'],
			'split-stray-closer' => ['split' => 'a>, b<c, d>, e'],
			'split-empty' => ['split' => ''],
			'split-null' => ['split' => null],
			'split-blank-tokens' => ['split' => ' , ,  '],
			'file-open-fails' => ['file' => 'false'],
			'file-open-throws' => ['file' => 'throw'],
			'file-stream' => ['file' => 'stream'],
		];
	}//end scenarios()

	private static function withAttachments(): string {
		return self::mixed(
			parts: [
				"Content-Type: text/plain\r\n\r\nbody\r\n",
				"Content-Type: application/pdf; name=\"report.pdf\"\r\nContent-Disposition: attachment; filename=\"../../etc/report.pdf\"\r\n"
					. "Content-Transfer-Encoding: base64\r\n\r\n" . base64_encode('pdf-bytes') . "\r\n",
				"Content-Type: application/octet-stream; name=\"by-type.bin\"\r\nContent-Disposition: attachment\r\n\r\nraw\r\n",
				"Content-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=\"..\\\\evil\\\\win.txt\"\r\n\r\nw\r\n",
				"Content-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=\"..\"\r\n\r\nd\r\n",
				"Content-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=\" .hidden \"\r\n\r\nh\r\n",
				"Content-Type: image/png\r\nContent-Disposition: INLINE; filename=\"img.png\"\r\nContent-ID: <abc@example.com>\r\n\r\npng\r\n",
				"Content-Type: image/gif\r\nContent-Disposition: inline\r\nContent-ID: <>\r\n\r\ngif\r\n",
				"Content-Disposition: attachment\r\n\r\nno type\r\n",
			]
		);
	}//end withAttachments()

	private static function nested(int $levels): string {
		$inner = "Subject: level 0\r\nFrom: inner@example.com\r\nContent-Type: text/plain\r\n\r\ninnermost body\r\n";
		for ($level = 1; $level <= $levels; $level++) {
			$inner = self::mixed(
				parts: [
					"Content-Type: text/plain\r\n\r\nouter body " . $level . "\r\n",
					"Content-Type: message/rfc822\r\nContent-Disposition: attachment; filename=\"level" . $level . ".eml\"\r\n\r\n" . $inner . "\r\n",
				],
				boundary: 'n' . $level,
				subject: 'level ' . $level
			);
		}

		return $inner;
	}//end nested()

	/**
	 * @param array<int, string> $parts
	 */
	private static function mixed(array $parts, string $boundary = 'mx', string $subject = 'mixed'): string {
		$eml = 'Subject: ' . $subject . "\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"" . $boundary . "\"\r\n\r\n";
		foreach ($parts as $part) {
			$eml .= '--' . $boundary . "\r\n" . $part;
		}

		return $eml . '--' . $boundary . "--\r\n";
	}//end mixed()

	/**
	 * @dataProvider cases
	 */
	public function testEmlParserMatchesGolden(string $name): void {
		$recorded = $this->record(scenario: self::scenarios()[$name]);

		$all = [];
		if (file_exists(self::FIXTURE) === true) {
			$all = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$all[$name] = $recorded;
			ksort($all);
			file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
		}

		$this->assertArrayHasKey($name, $all);
		$this->assertNotEmpty($all[$name]);
		$this->assertSame($all[$name], $recorded);
	}//end testEmlParserMatchesGolden()

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
		$parser = new EmlParser(logger: $logger);

		$out = [];
		$warnings = [];
		set_error_handler(
			static function (int $errno, string $errstr) use (&$warnings): bool {
				$warnings[] = $errno . ': ' . $errstr;
				return true;
			}
		);
		try {
			if (array_key_exists('split', $scenario) === true) {
				$split = new \ReflectionMethod(EmlParser::class, 'splitAddressList');
				$out['split'] = $split->invoke($parser, $scenario['split']);
				return $out;
			}

			$structure = $this->structureFor(parser: $parser, scenario: $scenario);
			$out['structure'] = $this->normalise(value: json_decode((string)json_encode($structure, JSON_INVALID_UTF8_SUBSTITUTE), true));
			if (($scenario['flatten'] ?? true) === true) {
				$out['flat'] = $this->attempt(fn () => $parser->flatten(structure: $structure));
			}
		} catch (Throwable $e) {
			$out['threw'] = get_class($e) . ': ' . $e->getMessage();
		} finally {
			restore_error_handler();
			$out['warnings'] = $warnings;
			$out['log'] = $lines;
		}

		return $out;
	}//end record()

	/**
	 * @param array<string, mixed> $scenario
	 */
	private function structureFor(EmlParser $parser, array $scenario): EmlStructure {
		if (isset($scenario['structure']) === true) {
			$built = $scenario['structure'];
			return new EmlStructure(
				headers: $built['headers'],
				body: new EmlBody(plainText: $built['plain'], html: $built['html']),
				attachments: []
			);
		}

		if (isset($scenario['file']) === true) {
			return $parser->parse(file: $this->file(mode: $scenario['file']));
		}

		$message = (new MailMimeParser())->parse($scenario['eml'], false);
		return $parser->parseMessage(message: $message, depth: ($scenario['depth'] ?? 0));
	}//end structureFor()

	private function file(string $mode): File {
		$file = $this->createMock(File::class);
		if ($mode === 'throw') {
			$file->method('fopen')->willThrowException(new RuntimeException('storage said "no" to alice@example.com'));
			return $file;
		}

		if ($mode === 'false') {
			$file->method('fopen')->willReturn(false);
			return $file;
		}

		$stream = fopen('php://memory', 'r+');
		fwrite($stream, "Subject: from file\r\nContent-Type: text/plain\r\n\r\nfile body\r\n");
		rewind($stream);
		$file->method('fopen')->willReturn($stream);
		return $file;
	}//end file()

	/**
	 * @return array<string, string>
	 */
	private function attempt(callable $call): array {
		try {
			return ['returned' => (string)json_encode($call(), JSON_INVALID_UTF8_SUBSTITUTE)];
		} catch (Throwable $e) {
			return ['threw' => get_class($e) . ': ' . $e->getMessage()];
		}
	}//end attempt()

	/**
	 * Attachment bytes are recorded by length and hash so the fixture stays readable.
	 */
	private function normalise(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $item) {
			if ($key === 'content' && is_string($item) === true) {
				$value[$key] = strlen($item) . ':' . md5($item);
				continue;
			}

			$value[$key] = $this->normalise(value: $item);
		}

		return $value;
	}//end normalise()
}//end class
