<?php

/**
 * A fragment may give a set's account an export it made: a small real file in
 * the account's Exports folder and the export run that names it, so a reader's
 * "Your exports" list has rows to show. Removed again exactly.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\ExportRun;
use OCA\OpenRegister\Db\ExportRunMapper;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns
 */
class ExampleSetExportRunsTest extends TestCase {
	/** @var array<string, string> The files in pieter's folder, by path. */
	private array $files = [];

	/** @var array<string, ExportRun> The runs, by uuid. */
	private array $runs = [];

	/**
	 * The kind over an in-memory user folder and run table.
	 *
	 * @return ExampleSetExportRuns
	 */
	private function exportRuns(): ExampleSetExportRuns {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturnCallback(fn (string $path): bool => isset($this->files[$path]) || $path === 'Exports');
		$folder->method('newFolder')->willReturnCallback(fn (string $path): Folder => $folder);
		$folder->method('newFile')->willReturnCallback(
			function (string $path, ?string $content = null): File {
				$this->files[$path] = (string) $content;
				return $this->file(path: $path);
			}
		);
		$folder->method('get')->willReturnCallback(
			function (string $path): mixed {
				if (isset($this->files[$path]) === false) {
					throw new NotFoundException($path);
				}

				return $this->file(path: $path);
			}
		);
		$folder->method('getById')->willReturnCallback(
			fn (int $id): array => array_values(array_map(fn (string $p): File => $this->file(path: $p), array_filter(array_keys($this->files), static fn (string $p): bool => crc32($p) === $id)))
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);

		$mapper = $this->createMock(ExportRunMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(fn (string $uuid): ExportRun => $this->runs[$uuid] ?? throw new \OCP\AppFramework\Db\DoesNotExistException($uuid));
		$mapper->method('update')->willReturnCallback(fn (ExportRun $run): ExportRun => $this->runs[$run->getUuid()] = $run);
		$mapper->method('delete')->willReturnCallback(function (ExportRun $run): ExportRun {
			unset($this->runs[$run->getUuid()]);
			return $run;
		});
		$recorder = $this->createMock(ExportRunRecorder::class);
		$recorder->method('record')->willReturnCallback(
			function (string $source, string $actor, string $format, int $rowCount, ?string $profile = null, ?string $filename = null, ?string $registerName = null, ?string $schemaName = null, ?int $fileId = null, ?string $filePath = null, ?int $retentionSeconds = null, int $downloadCount = 0): ExportRun {
				$run = new ExportRun();
				$run->setUuid('run-'.count($this->runs));
				$run->setSource($source);
				$run->setActor($actor);
				$run->setFormat($format);
				$run->setRowCount($rowCount);
				$run->setFilename($filename);
				$run->setRegisterName($registerName);
				$run->setSchemaName($schemaName);
				$run->setFileId($fileId);
				$run->setFilePath($filePath);
				$run->setRetentionSeconds($retentionSeconds);
				$run->setDownloadCount($downloadCount);
				$run->setProducedAt(new DateTime());
				return $this->runs[$run->getUuid()] = $run;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => match ($id) {
			IRootFolder::class       => $root,
			ExportRunMapper::class   => $mapper,
			ExportRunRecorder::class => $recorder,
		});

		return new ExampleSetExportRuns(container: $container);
	}//end exportRuns()

	/**
	 * A file in the store, with an id derived from its path.
	 *
	 * @param string $path The path.
	 *
	 * @return File
	 */
	private function file(string $path): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(crc32($path));
		$file->method('getPath')->willReturn('/pieter/files/'.$path);
		$file->method('delete')->willReturnCallback(function () use ($path): void {
			unset($this->files[$path]);
		});

		return $file;
	}//end file()

	/**
	 * The export is a real file and a run that names it, made at the declared moment; remove takes both away.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
	 */
	public function testAnExportIsAFileAndARunAndIsTakenAwayOnRemove(): void {
		$kind = $this->exportRuns();
		$data = [
			'user'          => 'pieter',
			'fileName'      => 'woo-2026-q3.csv',
			'format'        => 'csv',
			'content'       => "zaak;titel\n2026-0110;Verlichting fietspad Lindelaan\n",
			'rowCount'      => 1,
			'registerName'  => 'dossiq',
			'schemaName'    => 'case',
			'producedAt'    => '2026-10-23T14:05:00+02:00',
			'downloadCount' => 2,
		];

		$this->assertSame('', $kind->refusal(data: $data, users: ['pieter']));
		$made = $kind->write(app: 'dossiq', key: 'export-woo-q3', data: $data);

		$this->assertSame('', $made['error']);
		$this->assertSame("zaak;titel\n2026-0110;Verlichting fietspad Lindelaan\n", $this->files['Exports/woo-2026-q3.csv']);
		$run = $this->runs[$made['entry']['uuid']];
		$this->assertSame(['pieter', 'csv', 'example-set', 2, crc32('Exports/woo-2026-q3.csv')], [$run->getActor(), $run->getFormat(), $run->getSource(), $run->getDownloadCount(), $run->getFileId()]);
		$this->assertSame('2026-10-23 12:05', $run->getProducedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
		$this->assertFalse($kind->lost(entry: $made['entry']));

		$this->assertSame('removed', $kind->revert(entry: $made['entry']));
		$this->assertSame([], $this->files);
		$this->assertSame([], $this->runs);
		$this->assertTrue($kind->lost(entry: $made['entry']));
	}//end testAnExportIsAFileAndARunAndIsTakenAwayOnRemove()

	/**
	 * An account outside the set, a missing file name or content, a path out of the folder, or an unknown field refuse.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
	 */
	public function testAnExportThatCannotBeWrittenIsRefused(): void {
		$kind = $this->exportRuns();
		$ok   = ['user' => 'pieter', 'fileName' => 'a.csv', 'format' => 'csv', 'content' => 'x'];

		$this->assertStringContainsString('not an account', $kind->refusal(data: $ok, users: ['esra']));
		$this->assertStringContainsString('fileName', $kind->refusal(data: (['fileName' => '../a.csv'] + $ok), users: ['pieter']));
		$this->assertStringContainsString('content', $kind->refusal(data: array_diff_key($ok, ['content' => 1]), users: ['pieter']));
		$this->assertStringContainsString('owner', $kind->refusal(data: ($ok + ['owner' => 'x']), users: ['pieter']));

		// A binary file (a spreadsheet) comes as base64; anything that does not decode is refused.
		$binary = ['user' => 'pieter', 'fileName' => 'b.xlsx', 'format' => 'xlsx', 'contentBase64' => base64_encode("PK\x03\x04 sheet")];
		$this->assertSame('', $kind->refusal(data: $binary, users: ['pieter']));
		$this->assertStringContainsString('content', $kind->refusal(data: (['contentBase64' => '%%%'] + $binary), users: ['pieter']));
		$made = $kind->write(app: 'dossiq', key: 'export-xlsx', data: $binary);
		$this->assertSame("PK\x03\x04 sheet", $this->files['Exports/b.xlsx'], (string) $made['error']);
	}//end testAnExportThatCannotBeWrittenIsRefused()

}//end class
