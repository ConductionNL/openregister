<?php

/**
 * Characterisation tests for ExampleSetExportRuns::write.
 *
 * Pins the order of the folder calls (folder made when absent, an existing
 * file replaced), every value the recorder is handed, the backdating of the
 * run to its declared moment (and no update without one) and the error a
 * failing write returns.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\ExportRun;
use OCA\OpenRegister\Db\ExportRunMapper;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCA\OpenRegister\Service\Export\ExportRunContent;
use OCA\OpenRegister\Service\Export\ExportRunFile;
use OCA\OpenRegister\Service\Export\ExportRunOrigin;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

final class ExampleSetExportRunsWriteTest extends TestCase {

	/** @var array<int, string> Every call, in order, as "what args". */
	private array $calls = [];

	/** @var array<string, bool> The paths that exist in the user folder. */
	private array $existing = [];

	/** @var RuntimeException|null What writing the file throws, if anything. */
	private ?RuntimeException $failNewFile = null;

	/** @var int|null The retention the recorder gives the run. */
	private ?int $retention = null;

	/** @var array<int, array<int, string>> Per update: producedAt, created and expiresAt, as ATOM. */
	private array $updated = [];

	private function kind(): ExampleSetExportRuns {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(77);
		$file->method('getPath')->willReturn('/u1/files/Exports/r.csv');
		$file->method('delete')->willReturnCallback(function (): void {
			$this->calls[] = 'delete';
		});

		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturnCallback(function (string $path): bool {
			$this->calls[] = 'nodeExists ' . $path;
			return isset($this->existing[$path]);
		});
		$folder->method('newFolder')->willReturnCallback(function (string $path) use ($folder): Folder {
			$this->calls[] = 'newFolder ' . $path;
			return $folder;
		});
		$folder->method('get')->willReturnCallback(function (string $path) use ($file): File {
			$this->calls[] = 'get ' . $path;
			return $file;
		});
		$folder->method('newFile')->willReturnCallback(function (string $path, ?string $content = null) use ($file): File {
			$this->calls[] = 'newFile ' . $path . ' ' . (string)$content;
			if ($this->failNewFile !== null) {
				throw $this->failNewFile;
			}

			return $file;
		});

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(function (string $user) use ($folder): Folder {
			$this->calls[] = 'userFolder ' . $user;
			return $folder;
		});

		$recorder = $this->createMock(ExportRunRecorder::class);
		$recorder->method('record')->willReturnCallback(function (ExportRunOrigin $origin, ExportRunContent $content, ExportRunFile $file): ExportRun {
			$this->calls[] = 'record ' . json_encode([
				$origin->source, $origin->actor, $content->format, $content->rowCount, $content->registerName,
				$content->schemaName, $file->filename, $file->fileId, $file->filePath, $file->downloadCount,
			]);
			$run = new ExportRun();
			$run->setUuid('run-1');
			$run->setRetentionSeconds($this->retention);
			return $run;
		});

		$mapper = $this->createMock(ExportRunMapper::class);
		$mapper->method('update')->willReturnCallback(function (ExportRun $run): ExportRun {
			$this->calls[] = 'update';
			$this->updated[] = [
				$run->getProducedAt()->format(DATE_ATOM),
				$run->getCreated()->format(DATE_ATOM),
				$run->getExpiresAt()->format(DATE_ATOM),
			];
			$updated = new ExportRun();
			$updated->setUuid('run-updated');
			$updated->setProducedAt($run->getProducedAt());
			$updated->setCreated($run->getCreated());
			$updated->setExpiresAt($run->getExpiresAt());
			return $updated;
		});

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($root, $mapper, $recorder): object {
			$this->calls[] = 'container ' . substr($id, (int)strrpos($id, '\\') + 1);
			return match ($id) {
				IRootFolder::class => $root,
				ExportRunMapper::class => $mapper,
				ExportRunRecorder::class => $recorder,
			};
		});

		return new ExampleSetExportRuns(container: $container);
	}

	public function testANewExportMakesTheFolderAndRecordsTheDefaults(): void {
		$made = $this->kind()->write(new ExampleSetRow(app: 'a', key: 'k', data: ['user' => 'u1', 'fileName' => 'r.csv', 'content' => 'x;y']));

		$this->assertSame(['error' => '', 'entry' => ['uuid' => 'run-1', 'user' => 'u1', 'path' => 'Exports/r.csv']], $made);
		$this->assertSame(
			[
				'container IRootFolder',
				'userFolder u1',
				'nodeExists Exports',
				'newFolder Exports',
				'nodeExists Exports/r.csv',
				'newFile Exports/r.csv x;y',
				'container ExportRunRecorder',
				'record ["example-set","u1","csv",0,null,null,"r.csv",77,"\/u1\/files\/Exports\/r.csv",0]',
			],
			$this->calls
		);
	}

	public function testAnExistingFileIsReplacedAndAProducedAtBackdatesTheRun(): void {
		$this->existing = ['Exports' => true, 'Exports/r.csv' => true];
		$this->retention = 3600;

		$made = $this->kind()->write(
			new ExampleSetRow(
				app: 'a',
				key: 'k',
				data: [
					'user' => 'u1',
					'fileName' => 'r.csv',
					'format' => 'tsv',
					'contentBase64' => base64_encode('bin'),
					'rowCount' => '4',
					'registerName' => 'reg',
					'schemaName' => '',
					'downloadCount' => 3,
					'producedAt' => '2026-01-02T03:04:05+00:00',
				]
			)
		);

		$this->assertSame(['error' => '', 'entry' => ['uuid' => 'run-updated', 'user' => 'u1', 'path' => 'Exports/r.csv']], $made);
		$this->assertSame(
			[
				'container IRootFolder',
				'userFolder u1',
				'nodeExists Exports',
				'nodeExists Exports/r.csv',
				'get Exports/r.csv',
				'delete',
				'newFile Exports/r.csv bin',
				'container ExportRunRecorder',
				'record ["example-set","u1","tsv",4,"reg",null,"r.csv",77,"\/u1\/files\/Exports\/r.csv",3]',
				'container ExportRunMapper',
				'update',
			],
			$this->calls
		);
	}

	public function testTheBackdatedRunExpiresItsRetentionAfterTheDeclaredMoment(): void {
		$data = ['user' => 'u1', 'fileName' => 'r.csv', 'content' => 'x', 'producedAt' => '2026-01-02T03:04:05+00:00'];
		foreach ([3600, null] as $retention) {
			$this->retention = $retention;
			$this->kind()->write(new ExampleSetRow(app: 'a', key: 'k', data: $data));
		}

		$default = (new DateTime('2026-01-02T03:04:05+00:00'))
			->modify('+' . ExportRunRecorder::DEFAULT_RETENTION_SECONDS . ' seconds')
			->format(DATE_ATOM);
		$this->assertSame(
			[
				['2026-01-02T03:04:05+00:00', '2026-01-02T03:04:05+00:00', '2026-01-02T04:04:05+00:00'],
				['2026-01-02T03:04:05+00:00', '2026-01-02T03:04:05+00:00', $default],
			],
			$this->updated
		);
	}

	public function testAnUnreadableProducedAtLeavesTheRunAsRecorded(): void {
		$data = ['user' => 'u1', 'fileName' => 'r.csv', 'content' => 'x', 'producedAt' => 'yesterday-ish'];
		$made = $this->kind()->write(new ExampleSetRow(app: 'a', key: 'k', data: $data));

		$this->assertSame('run-1', $made['entry']['uuid']);
		$this->assertSame([], $this->updated);
	}

	public function testAFailedWriteReturnsTheErrorAndNoEntry(): void {
		$this->failNewFile = new RuntimeException('quota exceeded');

		$made = $this->kind()->write(new ExampleSetRow(app: 'a', key: 'k', data: ['user' => 'u1', 'fileName' => 'r.csv', 'content' => 'x']));

		$this->assertSame(['error' => 'quota exceeded', 'entry' => null], $made);
		$this->assertNotContains('container ExportRunRecorder', $this->calls);
	}
}//end class
