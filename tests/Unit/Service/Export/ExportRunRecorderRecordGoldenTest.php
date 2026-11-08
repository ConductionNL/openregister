<?php

/**
 * Golden test pinning every field ExportRunRecorder::record() writes.
 *
 * Written against the recorder before its arguments were grouped, so the
 * refactor is proven to write the same row: the same identity, content and
 * file columns, the same default status and download count, and the same
 * retention deadline, including both clamps and the keep-forever case.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\ExportRun;
use OCA\OpenRegister\Db\ExportRunMapper;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCA\OpenRegister\Service\Export\ExportRunContent;
use OCA\OpenRegister\Service\Export\ExportRunFile;
use OCA\OpenRegister\Service\Export\ExportRunOrigin;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ExportRunRecorderRecordGoldenTest extends TestCase {

	/**
	 * The fixed moment the fake clock reports.
	 *
	 * @var int
	 */
	private const NOW = 1790000000;

	public function testAFullRunWritesEveryColumn(): void {
		$run = $this->recorder()->record(
			origin: new ExportRunOrigin(
				source: 'scheduled-report',
				actor: 'alice',
				profile: 'weekly-cases',
				status: ExportRun::STATUS_EXPIRED
			),
			content: new ExportRunContent(
				format: 'csv',
				rowCount: 12,
				registerName: 'cases',
				schemaName: 'case'
			),
			file: new ExportRunFile(
				filename: 'weekly-cases.csv',
				fileId: 4242,
				filePath: 'Reports/weekly-cases.csv',
				downloadCount: 2
			),
			retentionSeconds: 3600
		);

		$this->assertSame(
			[
				'source' => 'scheduled-report',
				'profile' => 'weekly-cases',
				'actor' => 'alice',
				'registerName' => 'cases',
				'schemaName' => 'case',
				'format' => 'csv',
				'filename' => 'weekly-cases.csv',
				'rowCount' => 12,
				'fileId' => 4242,
				'filePath' => 'Reports/weekly-cases.csv',
				'downloadCount' => 2,
				'retentionSeconds' => 3600,
				'status' => ExportRun::STATUS_EXPIRED,
				'producedAt' => self::NOW,
				'expiresAt' => self::NOW + 3600,
			],
			$this->columns($run)
		);
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$run->getUuid());
	}

	public function testAMinimalRunTakesTheDefaults(): void {
		$run = $this->recorder()->record(
			origin: new ExportRunOrigin(
				source: 'api',
				actor: 'bob'
			),
			content: new ExportRunContent(
				format: 'json',
				rowCount: 0
			)
		);

		$this->assertSame(
			[
				'source' => 'api',
				'profile' => null,
				'actor' => 'bob',
				'registerName' => null,
				'schemaName' => null,
				'format' => 'json',
				'filename' => null,
				'rowCount' => 0,
				'fileId' => null,
				'filePath' => null,
				'downloadCount' => 0,
				'retentionSeconds' => ExportRunRecorder::DEFAULT_RETENTION_SECONDS,
				'status' => ExportRun::STATUS_AVAILABLE,
				'producedAt' => self::NOW,
				'expiresAt' => self::NOW + ExportRunRecorder::DEFAULT_RETENTION_SECONDS,
			],
			$this->columns($run)
		);
	}

	public function testATooShortRetentionIsRaisedToOneMinute(): void {
		$run = $this->recorder()->record(
			origin: new ExportRunOrigin(
				source: 'api',
				actor: 'bob'
			),
			content: new ExportRunContent(
				format: 'csv',
				rowCount: 1
			),
			retentionSeconds: 5
		);

		$this->assertSame(60, $run->getRetentionSeconds());
		$this->assertSame(self::NOW + 60, $run->getExpiresAt()?->getTimestamp());
	}

	public function testATooLongRetentionIsCappedAtTheMaximum(): void {
		$run = $this->recorder()->record(
			origin: new ExportRunOrigin(
				source: 'api',
				actor: 'bob'
			),
			content: new ExportRunContent(
				format: 'csv',
				rowCount: 1
			),
			retentionSeconds: 100000000
		);

		$this->assertSame(ExportRunRecorder::MAX_RETENTION_SECONDS, $run->getRetentionSeconds());
		$this->assertSame(self::NOW + ExportRunRecorder::MAX_RETENTION_SECONDS, $run->getExpiresAt()?->getTimestamp());
	}

	public function testANullRetentionKeepsTheFileWithoutADeadline(): void {
		$run = $this->recorder()->record(
			origin: new ExportRunOrigin(
				source: 'api',
				actor: 'bob',
				profile: 'ad-hoc'
			),
			content: new ExportRunContent(
				format: 'csv',
				rowCount: 3,
				registerName: 'cases',
				schemaName: 'case'
			),
			file: new ExportRunFile(
				downloadCount: 1
			),
			retentionSeconds: null
		);

		$this->assertNull($run->getRetentionSeconds());
		$this->assertNull($run->getExpiresAt());
		$this->assertSame(1, $run->getDownloadCount());
		$this->assertSame('ad-hoc', $run->getProfile());
	}

	/**
	 * The written columns of a run, timestamps as epoch seconds.
	 *
	 * @param ExportRun $run The run.
	 *
	 * @return array<string, mixed>
	 */
	private function columns(ExportRun $run): array {
		return [
			'source' => $run->getSource(),
			'profile' => $run->getProfile(),
			'actor' => $run->getActor(),
			'registerName' => $run->getRegisterName(),
			'schemaName' => $run->getSchemaName(),
			'format' => $run->getFormat(),
			'filename' => $run->getFilename(),
			'rowCount' => $run->getRowCount(),
			'fileId' => $run->getFileId(),
			'filePath' => $run->getFilePath(),
			'downloadCount' => $run->getDownloadCount(),
			'retentionSeconds' => $run->getRetentionSeconds(),
			'status' => $run->getStatus(),
			'producedAt' => $run->getProducedAt()?->getTimestamp(),
			'expiresAt' => $run->getExpiresAt()?->getTimestamp(),
		];
	}

	private function recorder(): ExportRunRecorder {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('@' . self::NOW));

		$mapper = $this->createMock(ExportRunMapper::class);
		$mapper->method('insert')->willReturnArgument(0);

		return new ExportRunRecorder(
			mapper: $mapper,
			rootFolder: $this->createMock(IRootFolder::class),
			time: $time,
			logger: new NullLogger()
		);
	}
}
