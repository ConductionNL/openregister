<?php

/**
 * Golden test for the SIP packages SipPackageBuilder writes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\MdtoXmlGenerator;
use OCA\OpenRegister\Service\Edepot\SipPackageBuilder;
use OCP\IAppConfig;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Pins every entry, its order and its bytes in the zip and BagIt packages,
 * single and split, plus every refusal: the checksums in each manifest are
 * verified against the bytes they describe, then masked, so only the moment
 * of packaging (PREMIS event time, Bagging-Date) is not pinned literally.
 *
 * @covers \OCA\OpenRegister\Service\Edepot\SipPackageBuilder
 */
class SipPackageBuilderGoldenTest extends TestCase {

	/**
	 * Every recorded event, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Files to remove after the test.
	 *
	 * @var list<string>
	 */
	private array $cleanup = [];

	/**
	 * Whether the temp manager fails.
	 *
	 * @var bool
	 */
	private bool $noTemp = false;

	/**
	 * The configured max package size.
	 *
	 * @var string|null
	 */
	private ?string $maxSize = null;

	protected function tearDown(): void {
		foreach ($this->cleanup as $path) {
			@unlink($path);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The builder over deterministic collaborators.
	 *
	 * @return SipPackageBuilder
	 */
	private function builder(): SipPackageBuilder {
		$mdto = $this->createMock(MdtoXmlGenerator::class);
		$mdto->method('assertTransferPreconditions')->willReturnCallback(
			function (ObjectEntity $object): void {
				$this->calls[] = ['preconditions', $object->getUuid()];
				if ($object->getUuid() === 'no-period') {
					throw new \InvalidArgumentException('it has no retention period');
				}
			}
		);
		$mdto->method('generate')->willReturnCallback(
			static fn (ObjectEntity $object, array $files = []): string => '<mdto object="'.$object->getUuid().'" files="'.count($files).'"/>'
		);
		$mdto->method('generateBestand')->willReturnCallback(
			static fn (ObjectEntity $object, array $file): string => '<bestand object="'.$object->getUuid().'" name="'.$file['name'].'"/>'
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->maxSize ?? $default)
		);

		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(
			function (string $postFix = ''): string|false {
				$this->calls[] = ['temp', $postFix];
				if ($this->noTemp === true) {
					return false;
				}

				$base            = (string)tempnam(sys_get_temp_dir(), 'sipgolden');
				$path            = $base.$postFix;
				$this->cleanup[] = $base;
				$this->cleanup[] = $path;
				return $path;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$context['path'] = basename((string)($context['path'] ?? ''));
				$context['path'] = (string)preg_replace('/^sipgolden\w+/', '<tmp>', $context['path']);
				$this->calls[] = ['info', $message, $context];
			}
		);

		return new SipPackageBuilder($mdto, $config, $temp, $logger);
	}//end builder()

	/**
	 * A real file with given bytes.
	 *
	 * @param string $bytes The bytes.
	 *
	 * @return string The path.
	 */
	private function file(string $bytes): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'sipsrc');
		file_put_contents($path, $bytes);
		$this->cleanup[] = $path;

		return $path;
	}//end file()

	/**
	 * Three objects: one with an original and a rendition, one with a file
	 * that is not on disk, one without files.
	 *
	 * @return list<array{object: ObjectEntity, files: list<array<string, mixed>>}>
	 */
	private function objects(): array {
		$a = $this->file(bytes: 'original bytes');
		$b = $this->file(bytes: 'rendition bytes, longer');
		$c = $this->file(bytes: 'third');

		return [
			$this->item(uuid: 'obj-1', files: [
				[
					'name' => 'brief.docx',
					'size' => 14,
					'format' => 'application/msword',
					'checksum' => hash('sha256', 'original bytes'),
					'path' => $a,
					'isRendition' => false,
				],
				[
					'name' => 'brief.pdf',
					'size' => 23,
					'format' => 'application/pdf',
					'checksum' => hash('sha256', 'rendition bytes, longer'),
					'path' => $b,
					'isRendition' => true,
				],
			]),
			$this->item(uuid: 'obj-2', files: [
				[
					'name' => 'gone.txt',
					'size' => 5,
					'format' => 'text/plain',
					'checksum' => 'abc',
					'path' => '/nonexistent/gone.txt',
					'isRendition' => false,
				],
				[
					'name' => 'third.txt',
					'size' => 5,
					'format' => 'text/plain',
					'checksum' => hash('sha256', 'third'),
					'path' => $c,
					'isRendition' => false,
				],
			]),
			$this->item(uuid: 'obj-3', files: []),
		];
	}//end objects()

	/**
	 * One object with its files.
	 *
	 * @param string                     $uuid  The uuid.
	 * @param list<array<string, mixed>> $files The files.
	 *
	 * @return array{object: ObjectEntity, files: list<array<string, mixed>>}
	 */
	private function item(string $uuid, array $files): array {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject(['title' => 'Object '.$uuid]);

		return ['object' => $object, 'files' => $files];
	}//end item()

	/**
	 * Record a package: every entry name in order with its normalised content.
	 *
	 * @param string $path The archive.
	 *
	 * @return void
	 */
	private function recordPackage(string $path): void {
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($path) === true);
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name           = (string)$zip->getNameIndex($i);
			$entries[$name] = (string)$zip->getFromIndex($i);
		}

		$zip->close();

		$out = [];
		foreach ($entries as $name => $content) {
			$out[] = [$name, $this->normaliseEntry(name: $name, content: $content, entries: $entries)];
		}

		$this->calls[] = ['package', $out];
	}//end recordPackage()

	/**
	 * Normalise one entry: verify and mask checksums, mask the packaging moment.
	 *
	 * @param string                $name    The entry name.
	 * @param string                $content The entry bytes.
	 * @param array<string, string> $entries Every entry of the package.
	 *
	 * @return mixed The normalised content.
	 */
	private function normaliseEntry(string $name, string $content, array $entries): mixed {
		if (str_ends_with($name, 'sip-manifest.json') === true) {
			$prefix   = substr($name, 0, -strlen('sip-manifest.json'));
			$manifest = json_decode($content, true);
			foreach ($manifest as $index => $row) {
				$bytes = $entries[$prefix.$row['path']];
				$this->assertSame(hash('sha256', $bytes), $row['checksum'], $row['path']);
				$manifest[$index]['checksum'] = '<sha256 ok>';
			}

			return $manifest;
		}

		if (in_array($name, ['manifest-sha256.txt', 'tagmanifest-sha256.txt'], true) === true) {
			$lines = [];
			foreach (array_filter(explode("\n", $content)) as $line) {
				[$sha, $file] = explode('  ', $line, 2);
				$this->assertSame(hash('sha256', $entries[$file]), $sha, $file);
				$lines[] = '<sha256 ok>  '.$file;
			}

			return $lines;
		}

		$content = (string)preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}/', '<now>', $content);

		return (string)preg_replace('/Bagging-Date: \d{4}-\d{2}-\d{2}/', 'Bagging-Date: <today>', $content);
	}//end normaliseEntry()

	/**
	 * Build and record every package, or the refusal.
	 *
	 * @param array<int, array<string, mixed>> $objects The objects.
	 * @param int                              $max     The max package size.
	 * @param string                           $format  The format.
	 *
	 * @return void
	 */
	private function buildAndRecord(array $objects, int $max, string $format): void {
		try {
			$paths = $this->builder()->build('transfer-9', $objects, $max, $format);
		} catch (\Throwable $e) {
			$this->calls[] = ['refused', get_class($e), $e->getMessage()];
			return;
		}

		$this->calls[] = ['packages', count($paths)];
		foreach ($paths as $path) {
			$this->recordPackage(path: $path);
		}
	}//end buildAndRecord()

	public function testASingleZipPackage(): void {
		$this->buildAndRecord(objects: $this->objects(), max: 0, format: 'zip');

		$this->assertMatchesGolden(name: 'zip-single');
	}

	public function testASingleBagitPackage(): void {
		$this->buildAndRecord(objects: $this->objects(), max: 0, format: 'bagit');

		$this->assertMatchesGolden(name: 'bagit-single');
	}

	public function testASplitZipTransfer(): void {
		$this->buildAndRecord(objects: $this->objects(), max: 20, format: 'zip');

		$this->assertMatchesGolden(name: 'zip-split');
	}

	public function testASplitBagitTransferFromTheConfiguredSize(): void {
		$this->maxSize = '30';
		$this->buildAndRecord(objects: $this->objects(), max: 0, format: 'bagit');

		$this->assertMatchesGolden(name: 'bagit-split');
	}

	public function testEveryRefusal(): void {
		$this->buildAndRecord(objects: [], max: 0, format: 'zip');
		$this->buildAndRecord(objects: $this->objects(), max: 0, format: 'tar');
		$this->buildAndRecord(objects: [$this->item(uuid: 'obj-1', files: []), $this->item(uuid: 'no-period', files: [])], max: 0, format: 'zip');
		$this->noTemp = true;
		$this->buildAndRecord(objects: $this->objects(), max: 0, format: 'zip');
		$this->buildAndRecord(objects: $this->objects(), max: 0, format: 'bagit');

		$this->assertMatchesGolden(name: 'refusals');
	}

	/**
	 * Compare the recorded events with the stored golden file.
	 *
	 * @param string $name The golden name.
	 *
	 * @return void
	 */
	private function assertMatchesGolden(string $name): void {
		$actual = json_encode($this->calls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
		$path   = __DIR__.'/fixtures/sip-golden/'.$name.'.json';
		if (getenv('GOLDEN_WRITE') === '1') {
			@mkdir(dirname($path), 0777, true);
			file_put_contents($path, $actual);
		}

		$this->assertFileExists($path);
		$this->assertSame(file_get_contents($path), $actual);
	}//end assertMatchesGolden()
}//end class
