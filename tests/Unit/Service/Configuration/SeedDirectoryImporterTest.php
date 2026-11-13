<?php

/**
 * Tests for SeedDirectoryImporter: the operator's mounted seed directory.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use DateTime;
use OCA\OpenRegister\Service\AppConfigurationImporterInterface;
use OCA\OpenRegister\Service\CauseFrame;
use OCA\OpenRegister\Service\Configuration\SeedDirectoryImporter;
use OCA\OpenRegister\Service\Configuration\SeedReport;
use OCA\OpenRegister\Service\WriteCause;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(SeedDirectoryImporter::class)]
#[UsesClass(SeedReport::class)]
#[UsesClass(WriteCause::class)]
#[UsesClass(CauseFrame::class)]
class SeedDirectoryImporterTest extends TestCase {

	/** The seed directory of one test. */
	private string $dir;

	/** A directory beside it, outside the seed directory. */
	private string $outside;

	/** What config.php answers for the setting. */
	private string $setting;

	/** @var array<string, string> The app config store. */
	private array $store = [];

	/** @var list<array{appId: string, data: array, version: string, force: bool, cause: string, run: ?string}> */
	private array $imports = [];

	/** @var callable|null What the importer does per call; returns the result. */
	private $importBehaviour = null;

	private AppConfigurationImporterInterface&MockObject $importer;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$base          = sys_get_temp_dir() . '/seedtest-' . bin2hex(random_bytes(6));
		$this->dir     = $base . '/seed';
		$this->outside = $base . '/outside';
		mkdir($this->dir, 0777, true);
		mkdir($this->outside, 0777, true);
		$this->setting = $this->dir;
		WriteCause::reset();

		$this->importer = $this->createMock(AppConfigurationImporterInterface::class);
		$this->importer->method('importFromApp')->willReturnCallback(
			function (string $appId, array $data, string $version, bool $force = false): array {
				$frame           = WriteCause::current();
				$this->imports[] = ['appId' => $appId, 'data' => $data, 'version' => $version, 'force' => $force, 'cause' => $frame['cause'], 'run' => $frame['run']];
				if ($this->importBehaviour !== null) {
					return ($this->importBehaviour)($appId, $data);
				}

				return $this->importResult(registers: count($data['components']['registers'] ?? []), objects: count($data['objects'] ?? []));
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	protected function tearDown(): void {
		$this->removeTree(path: dirname($this->dir));
		WriteCause::reset();
	}//end tearDown()

	private function removeTree(string $path): void {
		if (is_link($path) === true || is_file($path) === true) {
			unlink($path);
			return;
		}

		if (is_dir($path) === false) {
			return;
		}

		foreach (array_diff(scandir($path), ['.', '..']) as $name) {
			$this->removeTree(path: $path . '/' . $name);
		}

		rmdir($path);
	}//end removeTree()

	private function importer(): SeedDirectoryImporter {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			fn (string $key, string $default = ''): string => $key === SeedDirectoryImporter::CONFIG_KEY ? $this->setting : $default
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '', bool $lazy = false): string => $this->store[$key] ?? $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false): bool {
				$this->store[$key] = $value;
				return true;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-13T10:00:00+00:00'));

		return new SeedDirectoryImporter(config: $config, appConfig: $appConfig, importer: $this->importer, time: $time, logger: $this->logger);
	}//end importer()

	private function importResult(int $registers = 0, int $objects = 0): array {
		return [
			'registers' => array_fill(0, $registers, 'register'),
			'schemas'   => [],
			'objects'   => array_fill(0, $objects, 'object'),
			'skipped'   => ['registers' => 0, 'schemas' => 0, 'objects' => 0, 'mappings' => 0, 'seedObjects' => 0],
			'unchanged' => ['objects' => 0],
			'failed'    => ['schemas' => [], 'objects' => []],
		];
	}//end importResult()

	private function write(string $name, mixed $content): void {
		$text = $content;
		if (is_string($content) === false) {
			$text = json_encode($content);
		}

		file_put_contents($this->dir . '/' . $name, $text);
	}//end write()

	private function seedFiles(): void {
		$this->write(name: '20-objects.json', content: ['version' => '1.0.0', 'objects' => [['title' => 'a'], ['title' => 'b']]]);
		$this->write(name: '10-registers.json', content: ['version' => '2.1.0', 'components' => ['registers' => ['r' => ['slug' => 'r']]]]);
	}//end seedFiles()

	public function testFilesImportInLexicalOrder(): void {
		$this->seedFiles();
		$this->write(name: 'README.txt', content: 'not a seed');
		mkdir($this->dir . '/nested.json');
		file_put_contents($this->dir . '/nested.json/30-hidden.json', '{}');

		$report = $this->importer()->import();

		self::assertSame(['openregister-seed.10-registers', 'openregister-seed.20-objects'], array_column($this->imports, 'appId'));
		self::assertSame(['2.1.0', '1.0.0'], array_column($this->imports, 'version'));
		self::assertSame([false, false], array_column($this->imports, 'force'));
		self::assertSame([WriteCause::SEED, WriteCause::SEED], array_column($this->imports, 'cause'));
		self::assertSame('seed-' . substr($report->digest, 0, 12), $this->imports[0]['run']);
		self::assertSame(['10-registers.json', '20-objects.json'], array_column($report->files, 'file'));
		self::assertSame(SeedReport::STATUS_SUCCESS, $report->status());
		self::assertSame(1, $report->files[0]['counts']['registers']['imported']);
		self::assertSame(2, $report->files[1]['counts']['objects']['imported']);
		self::assertSame(WriteCause::PERSON, WriteCause::current()['cause'], 'the seed frame is closed after the run');
	}//end testFilesImportInLexicalOrder()

	public function testASecondRunUpdatesOrSkips(): void {
		$this->seedFiles();
		$importer = $this->importer();

		self::assertNotNull($importer->importIfChanged());
		self::assertCount(2, $this->imports);

		self::assertNull($importer->importIfChanged(), 'an unchanged directory imports nothing');
		self::assertCount(2, $this->imports);

		$this->write(name: '20-objects.json', content: ['version' => '1.0.0', 'objects' => [['title' => 'a'], ['title' => 'b, retitled']]]);
		$report = $importer->importIfChanged();

		self::assertNotNull($report);
		self::assertCount(4, $this->imports, 'a changed file re-imports the directory through the importer, which matches by slug or uuid');
		self::assertSame($this->imports[1]['appId'], $this->imports[3]['appId'], 'the same file imports under the same app id, so it updates instead of duplicating');
		self::assertSame('b, retitled', $this->imports[3]['data']['objects'][1]['title']);
	}//end testASecondRunUpdatesOrSkips()

	public function testASymlinkOutsideTheDirectoryIsRefused(): void {
		$this->seedFiles();
		file_put_contents($this->outside . '/secret.json', '{"objects":[{"x":1}]}');
		symlink($this->outside . '/secret.json', $this->dir . '/99-secrets.json');
		file_put_contents($this->dir . '/15-inside.json', '{"version":"1.0.0"}');
		symlink($this->dir . '/15-inside.json', $this->dir . '/16-alias.json');

		$report = $this->importer()->import();

		self::assertSame(
			['openregister-seed.10-registers', 'openregister-seed.15-inside', 'openregister-seed.16-alias', 'openregister-seed.20-objects'],
			array_column($this->imports, 'appId'),
			'a link that stays inside the directory imports; the one leading out is never read'
		);
		$file = $report->files[4];
		self::assertSame('99-secrets.json', $file['file']);
		self::assertSame(SeedReport::FILE_REFUSED, $file['status']);
		self::assertSame('99-secrets.json resolves outside the seed directory', $file['failures'][0]['reason']);
		self::assertSame(SeedReport::STATUS_PARTIAL, $report->status());
	}//end testASymlinkOutsideTheDirectoryIsRefused()

	public function testAFileOverFiftyMegabytesIsRefused(): void {
		$this->seedFiles();
		$handle = fopen($this->dir . '/50-huge.json', 'w');
		ftruncate($handle, SeedDirectoryImporter::MAX_FILE_BYTES + 1);
		fclose($handle);

		$report = $this->importer()->import();

		self::assertCount(2, $this->imports);
		self::assertSame(SeedReport::FILE_REFUSED, $report->files[2]['status']);
		self::assertSame('50-huge.json is larger than 50 MB', $report->files[2]['failures'][0]['reason']);
	}//end testAFileOverFiftyMegabytesIsRefused()

	public function testAMalformedFileIsNamedAndOthersImport(): void {
		$this->seedFiles();
		$this->write(name: '15-broken.json', content: '{"objects": [');

		$report = $this->importer()->import();

		self::assertSame(['openregister-seed.10-registers', 'openregister-seed.20-objects'], array_column($this->imports, 'appId'));
		self::assertSame('15-broken.json', $report->files[1]['file']);
		self::assertSame(SeedReport::FILE_FAILED, $report->files[1]['status']);
		self::assertStringStartsWith('15-broken.json is not valid JSON: ', $report->files[1]['failures'][0]['reason']);
		self::assertSame(SeedReport::STATUS_PARTIAL, $report->status());
		self::assertSame(SeedReport::STATUS_PARTIAL, $this->importer()->lastReport()['status'], 'the report is stored');
	}//end testAMalformedFileIsNamedAndOthersImport()

	public function testAFileTheImportRejectsIsNamedAndOthersImport(): void {
		$this->seedFiles();
		$this->importBehaviour = function (string $appId, array $data): array {
			if ($appId === 'openregister-seed.10-registers') {
				throw new RuntimeException('Failed to import configuration for app ' . $appId . ': bad register');
			}

			return $this->importResult(objects: 2);
		};

		$report = $this->importer()->import();

		self::assertSame(SeedReport::FILE_FAILED, $report->files[0]['status']);
		self::assertSame('10-registers.json could not be imported: Failed to import configuration for app openregister-seed.10-registers: bad register', $report->files[0]['failures'][0]['reason']);
		self::assertSame(2, $report->files[1]['counts']['objects']['imported']);
		self::assertSame(SeedReport::STATUS_PARTIAL, $report->status());
	}//end testAFileTheImportRejectsIsNamedAndOthersImport()

	public function testNoSettingDoesNothing(): void {
		$this->setting = '  ';
		$this->seedFiles();
		$this->logger->expects(self::never())->method(self::anything());

		self::assertNull($this->importer()->import());
		self::assertNull($this->importer()->importIfChanged());
		self::assertSame([], $this->imports);
		self::assertSame([], $this->store);
	}//end testNoSettingDoesNothing()

	public function testARelativePathIsReported(): void {
		$this->setting = 'seed';
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('the seed directory "seed" is not an absolute path'));

		$report = $this->importer()->import();

		self::assertSame(SeedReport::STATUS_FAILED, $report->status());
		self::assertSame(['the seed directory "seed" is not an absolute path'], $report->problems);
		self::assertSame([], $this->imports);
		self::assertSame('failed', $this->importer()->lastReport()['status']);
		self::assertArrayNotHasKey(SeedDirectoryImporter::DIGEST_KEY, $this->store, 'an unread directory leaves the digest alone');
	}//end testARelativePathIsReported()

	public function testAMissingDirectoryIsReported(): void {
		$this->setting = $this->dir . '/absent';

		$report = $this->importer()->import();

		self::assertSame(['the seed directory "' . $this->dir . '/absent" is not a readable directory'], $report->problems);
		self::assertSame([], $this->imports);
	}//end testAMissingDirectoryIsReported()

	public function testLastReportIsNullBeforeAnyRunAndWhenUnreadable(): void {
		self::assertNull($this->importer()->lastReport());

		$this->store[SeedDirectoryImporter::REPORT_KEY] = 'not json';
		self::assertNull($this->importer()->lastReport());
	}//end testLastReportIsNullBeforeAnyRunAndWhenUnreadable()
}//end class
