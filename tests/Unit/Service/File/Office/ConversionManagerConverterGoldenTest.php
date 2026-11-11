<?php

/**
 * ConversionManagerConverter golden test.
 *
 * Records what ConversionManagerConverter returns, throws and asks of the
 * conversion API and the work folder, so a refactor of the converter can be
 * shown to keep its behaviour.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File\Office
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
 * @spec openspec/specs/export-pdf-format/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File\Office;

use OCA\OpenRegister\Files\Service\File\Office\ConversionManagerConverter;
use OCA\OpenRegister\Files\Service\File\Office\ConversionWorkFolder;
use OCP\Files\Conversion\ConversionMimeProvider;
use OCP\Files\Conversion\IConversionManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Files\Service\File\Office\ConversionManagerConverter
 */
class ConversionManagerConverterGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/conversion-manager-converter-golden.json';

	private const MIME = [
		'odt' => 'application/vnd.oasis.opendocument.text',
		'pdf' => 'application/pdf',
		'doc' => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'txt' => 'text/plain',
	];

	/**
	 * Calls made against the doubles, in order, with the random file name masked.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The random names the converter used.
	 *
	 * @var array<string, true>
	 */
	private array $names = [];

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
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$all = [['pdf', 'odt'], ['odt', 'pdf'], ['doc', 'docx'], ['doc', 'txt']];
		return [
			'available' => ['providers' => [['odt', 'pdf']], 'call' => ['isAvailable']],
			'not-available' => ['providers' => [['pdf', 'odt'], ['odt', 'docx']], 'call' => ['isAvailable']],
			'providers-throw' => ['providers' => 'throw', 'call' => ['isAvailable']],
			'pdf-to-odt' => ['providers' => $all, 'call' => ['pdfToOdt', 'PDF']],
			'odt-to-pdf' => ['providers' => $all, 'call' => ['odtToPdf', 'ODT']],
			'odt-to-pdf-unsupported' => ['providers' => [['pdf', 'odt']], 'call' => ['odtToPdf', 'ODT']],
			'odt-to-pdf-providers-throw' => ['providers' => 'throw', 'call' => ['odtToPdf', 'ODT']],
			'doc-target-docx' => ['providers' => $all, 'call' => ['legacyDocTarget']],
			'doc-target-txt' => ['providers' => [['doc', 'txt']], 'call' => ['legacyDocTarget']],
			'doc-target-none' => ['providers' => [['doc', 'pdf']], 'call' => ['legacyDocTarget']],
			'doc-to-txt' => ['providers' => $all, 'call' => ['convertLegacyDoc', 'DOC', 'txt']],
			'doc-to-docx-unsupported' => ['providers' => [['doc', 'txt']], 'call' => ['convertLegacyDoc', 'DOC', 'docx']],
			'doc-to-pdf-refused' => ['providers' => $all, 'call' => ['convertLegacyDoc', 'DOC', 'pdf']],
			'convert-throws' => ['providers' => $all, 'convert' => 'throw', 'call' => ['pdfToOdt', 'PDF']],
			'result-is-folder' => ['providers' => $all, 'result' => 'folder', 'call' => ['pdfToOdt', 'PDF']],
			'result-missing' => ['providers' => $all, 'result' => 'missing', 'call' => ['pdfToOdt', 'PDF']],
			'result-unreadable' => ['providers' => $all, 'result' => 'unreadable', 'call' => ['pdfToOdt', 'PDF']],
			'result-undeletable' => ['providers' => $all, 'result' => 'undeletable', 'call' => ['pdfToOdt', 'PDF']],
			'result-elsewhere' => ['providers' => $all, 'convertPath' => '/somewhere/else/other.odt', 'call' => ['pdfToOdt', 'PDF']],
			'source-undeletable' => ['providers' => $all, 'source' => 'undeletable', 'call' => ['pdfToOdt', 'PDF']],
			'source-undeletable-after-failure' => ['providers' => $all, 'source' => 'undeletable', 'convert' => 'throw', 'call' => ['pdfToOdt', 'PDF']],
			'folder-path-throws' => ['providers' => $all, 'path' => 'throw', 'call' => ['pdfToOdt', 'PDF']],
			'work-folder-throws' => ['providers' => $all, 'work' => 'throw', 'call' => ['pdfToOdt', 'PDF']],
			'new-file-throws' => ['providers' => $all, 'newFile' => 'throw', 'call' => ['pdfToOdt', 'PDF']],
		];
	}//end scenarios()

	/**
	 * @dataProvider cases
	 */
	public function testConverterMatchesGolden(string $name): void {
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
	}//end testConverterMatchesGolden()

	/**
	 * @param array<string, mixed> $scenario
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $scenario): array {
		$this->calls = [];
		$this->names = [];
		$converter = new ConversionManagerConverter($this->manager(scenario: $scenario), $this->workFolder(scenario: $scenario));

		$call = $scenario['call'];
		$method = array_shift($call);
		try {
			$returned = $converter->$method(...$call);
			if (is_string($returned) === true) {
				$returned = $this->mask(text: $returned);
			}

			$result = ['returned' => $returned];
		} catch (Throwable $e) {
			$result = ['threw' => $this->describe(error: $e)];
		}

		return ['result' => $result, 'calls' => $this->calls, 'distinctNames' => count($this->names)];
	}//end record()

	private function describe(Throwable $error): string {
		$text = get_class($error) . ': ' . $this->mask(text: $error->getMessage());
		if ($error->getPrevious() !== null) {
			$text .= ' <- ' . $this->describe(error: $error->getPrevious());
		}

		return $text;
	}//end describe()

	private function mask(string $text): string {
		return (string)preg_replace_callback(
			'/[0-9a-f]{32}/',
			function (array $match): string {
				$this->names[$match[0]] = true;
				return '<name>';
			},
			$text
		);
	}//end mask()

	private function log(string $call): void {
		$this->calls[] = $this->mask(text: $call);
	}//end log()

	/**
	 * @param array<string, mixed> $scenario
	 */
	private function manager(array $scenario): IConversionManager {
		$manager = $this->createMock(IConversionManager::class);
		$manager->method('getProviders')->willReturnCallback(
			function () use ($scenario): array {
				$this->log(call: 'getProviders()');
				if ($scenario['providers'] === 'throw') {
					throw new RuntimeException('provider registry down');
				}

				return array_map(
					static fn (array $pair): ConversionMimeProvider => new ConversionMimeProvider(self::MIME[$pair[0]], self::MIME[$pair[1]], $pair[1], $pair[1]),
					$scenario['providers']
				);
			}
		);
		$manager->method('convert')->willReturnCallback(
			function (File $file, string $targetMimeType, ?string $destination = null) use ($scenario): string {
				$this->log(call: 'convert(' . $file->getName() . ', ' . $targetMimeType . ', ' . $destination . ')');
				if (($scenario['convert'] ?? null) === 'throw') {
					throw new RuntimeException('richdocuments refused');
				}

				return ($scenario['convertPath'] ?? (string)$destination);
			}
		);

		return $manager;
	}//end manager()

	/**
	 * @param array<string, mixed> $scenario
	 */
	private function workFolder(array $scenario): ConversionWorkFolder {
		$work = $this->createMock(ConversionWorkFolder::class);
		$work->method('get')->willReturnCallback(
			function () use ($scenario): Folder {
				$this->log(call: 'workFolder.get()');
				if (($scenario['work'] ?? null) === 'throw') {
					throw new NotFoundException('no work folder');
				}

				return $this->folder(scenario: $scenario);
			}
		);

		return $work;
	}//end workFolder()

	/**
	 * @param array<string, mixed> $scenario
	 */
	private function folder(array $scenario): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturnCallback(
			function () use ($scenario): string {
				$this->log(call: 'folder.getPath()');
				if (($scenario['path'] ?? null) === 'throw') {
					throw new NotFoundException('folder gone');
				}

				return '/openregister/files/conversions';
			}
		);
		$folder->method('newFile')->willReturnCallback(
			function (string $path, $content = null) use ($scenario): File {
				$this->log(call: 'folder.newFile(' . $path . ', ' . $content . ')');
				if (($scenario['newFile'] ?? null) === 'throw') {
					throw new RuntimeException('disk full');
				}

				return $this->file(name: $path, content: (string)$content, mode: ($scenario['source'] ?? 'ok'));
			}
		);
		$folder->method('get')->willReturnCallback(
			function (string $path) use ($scenario) {
				$this->log(call: 'folder.get(' . $path . ')');
				return $this->convertedNode(name: $path, mode: ($scenario['result'] ?? 'ok'));
			}
		);

		return $folder;
	}//end folder()

	private function convertedNode(string $name, string $mode): File|Folder {
		if ($mode === 'missing') {
			throw new NotFoundException('no result');
		}

		if ($mode === 'folder') {
			return $this->createMock(Folder::class);
		}

		return $this->file(name: $name, content: 'converted:' . $name, mode: $mode);
	}//end convertedNode()

	private function file(string $name, string $content, string $mode): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturnCallback(
			function () use ($name, $content, $mode): string {
				$this->log(call: 'getContent(' . $name . ')');
				if ($mode === 'unreadable') {
					throw new RuntimeException('cannot read result');
				}

				return $content;
			}
		);
		$file->method('delete')->willReturnCallback(
			function () use ($name, $mode): void {
				$this->log(call: 'delete(' . $name . ')');
				if ($mode === 'undeletable') {
					throw new RuntimeException('cannot delete ' . $name);
				}
			}
		);

		return $file;
	}//end file()
}//end class
