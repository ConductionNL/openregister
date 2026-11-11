<?php

/**
 * ExecutableContentDetector golden test.
 *
 * Records what each ExecutableContentDetector method reports for a set of
 * content prefixes and file names, so a refactor of the detector can be shown
 * to keep its behaviour.
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

use OCA\OpenRegister\Service\File\ExecutableContentDetector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\File\ExecutableContentDetector
 */
class ExecutableContentDetectorGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/executable-content-detector-golden.json';

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$out = [];
		foreach (array_keys(self::contents()) as $name) {
			$out[$name] = [$name];
		}

		return $out;
	}//end cases()

	/**
	 * Content prefixes, each asked of every method under every file name.
	 *
	 * @return array<string, string>
	 */
	private static function contents(): array {
		return [
			'empty' => '',
			'png' => "\x89PNG\r\n\x1A\nrest",
			'jpeg' => "\xFF\xD8\xFF\xE0",
			'gif87' => 'GIF87a...',
			'gif89' => 'GIF89a...',
			'tiff-le' => "II*\x00....",
			'tiff-be' => "MM\x00*....",
			'ico' => "\x00\x00\x01\x00....",
			'psd' => '8BPS....',
			'riff' => 'RIFF....WEBP',
			'pdf' => "%PDF-1.7\n<?php echo 1; ?>",
			'ole' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1....",
			'zip' => "PK\x03\x04....",
			'zip-empty' => "PK\x05\x06....",
			'zip-spanned' => "PK\x07\x08....",
			'gzip' => "\x1F\x8B\x08",
			'bzip2' => 'BZh91AY',
			'xz' => "\xFD7zXZ\x00..",
			'7z' => "7z\xBC\xAF\x27\x1C..",
			'rar' => "Rar!\x1A\x07\x00",
			'mp3' => 'ID3....',
			'ogg' => 'OggS....',
			'flac' => 'fLaC....',
			'mkv' => "\x1A\x45\xDF\xA3....",
			'flv' => "FLV\x01....",
			'sqlite' => "SQLite format 3\x00....",
			'mp4' => "\x00\x00\x00\x18ftypmp42",
			'mp4-short' => "\x00\x00\x00\x18ftypmp4",
			'ftyp-elsewhere' => "\x00\x00\x00\x18xftypmp42",
			'elf' => "\x7FELF\x02\x01",
			'mz' => "MZ\x90\x00",
			'java' => "\xCA\xFE\xBA\xBE",
			'shebang' => "#!/bin/sh\necho hi",
			'shebang-space' => "#! /usr/bin/env python",
			'php-tag' => "hello <?php echo 1; ?>",
			'php-short' => 'hello <?= 1 ?>',
			'php-upper' => 'hello <?PHP echo 1;',
			'xml' => '<?xml version="1.0"?><a/>',
			'text' => 'plain text only',
			'php-past-prefix' => str_repeat('a', 1100) . '<?php',
		];
	}//end contents()

	/**
	 * @dataProvider cases
	 */
	public function testExecutableContentDetectorMatchesGolden(string $name): void {
		$recorded = $this->record(content: self::contents()[$name]);

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
	}//end testExecutableContentDetectorMatchesGolden()

	/**
	 * @return array<string, mixed>
	 */
	private function record(string $content): array {
		$detector = new ExecutableContentDetector();
		$out = [
			'sniffBinaryContentType' => $detector->sniffBinaryContentType($content),
			'matchExecutableSignature' => $detector->matchExecutableSignature($content),
			'hasScriptShebang' => $detector->hasScriptShebang($content),
		];
		foreach (['a.txt', 'a.php', 'a.PHTML', 'a.svg', 'a.png', 'a.pdf', 'noext', 'a.php.jpg'] as $fileName) {
			$out['byName'][$fileName] = [
				'containsEmbeddedPhpTag' => $detector->containsEmbeddedPhpTag($content, $fileName),
				'shouldScanForEmbeddedPhp' => $detector->shouldScanForEmbeddedPhp($content, $fileName),
				'hasAlwaysScannedExtension' => $detector->hasAlwaysScannedExtension($fileName),
			];
		}

		return $out;
	}//end record()
}//end class
