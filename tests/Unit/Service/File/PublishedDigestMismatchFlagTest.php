<?php

/**
 * A published file found changed is marked on its record, for the operations console.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use DateTime;
use OCA\OpenRegister\BackgroundJob\PublishedDigestVerifyJob;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\FileDigest;
use OCA\OpenRegister\Db\FileDigestMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\PublishedDigestService;
use OCA\OpenRegister\Service\File\PublishedDownloadGuard;
use OCA\OpenRegister\Service\File\PublishedFileChangedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The download guard and the nightly job mark a changed file's record before anything else can fail.
 */
#[CoversClass(PublishedDigestService::class)]
#[CoversClass(PublishedDownloadGuard::class)]
#[CoversClass(PublishedDigestVerifyJob::class)]
#[UsesClass(FileDigest::class)]
#[UsesClass(PublishedFileChangedException::class)]
class PublishedDigestMismatchFlagTest extends TestCase {

	private const BYTES = 'the published decision';

	/**
	 * The marks written: record id, moment, current digest.
	 *
	 * @var list<array{0: int, 1: DateTime, 2: string}>
	 */
	private array $flags = [];

	/**
	 * Audit rows written.
	 *
	 * @var list<AuditTrail>
	 */
	private array $rows = [];

	/**
	 * Error messages logged.
	 *
	 * @var list<string>
	 */
	private array $errors = [];

	public function testTheServiceMarksTheRecordWithTheCurrentDigest(): void {
		$before = new DateTime();
		$this->service(digests: $this->digests())->flagMismatch(record: $this->record(), current: 'current-digest');

		$this->assertCount(1, $this->flags);
		$this->assertSame(7, $this->flags[0][0]);
		$this->assertGreaterThanOrEqual($before->getTimestamp(), $this->flags[0][1]->getTimestamp());
		$this->assertSame('current-digest', $this->flags[0][2]);
	}

	public function testARefusedDownloadMarksTheRecordEvenWithoutAnObject(): void {
		$digests = $this->digests();
		$guard = new PublishedDownloadGuard(digests: $digests, service: $this->service(digests: $digests), logger: $this->logger());

		try {
			$guard->reprDigestFor(file: $this->file(bytes: 'replaced', etag: 'etag-2'), object: null);
			$this->fail('A changed file must be refused.');
		} catch (PublishedFileChangedException $refusal) {
			$this->assertSame(42, $refusal->fileId);
		}

		$this->assertSame([[7, hash('sha256', 'replaced')]], $this->flagged());
		$this->assertSame([], $this->rows, 'Without the object there is no audit row, and the mark is still written.');
	}

	public function testAServedDownloadMarksNothing(): void {
		$digests = $this->digests();
		$guard = new PublishedDownloadGuard(digests: $digests, service: $this->service(digests: $digests), logger: $this->logger());

		$guard->reprDigestFor(file: $this->file(bytes: self::BYTES, etag: 'etag-2'), object: new ObjectEntity());

		$this->assertSame([], $this->flags);
	}

	public function testAMarkThatCannotBeWrittenStillRefusesAndStillAudits(): void {
		$digests = $this->digests(flagFails: true);
		$guard = new PublishedDownloadGuard(digests: $digests, service: $this->service(digests: $digests), logger: $this->logger());

		$this->expectException(PublishedFileChangedException::class);
		try {
			$guard->reprDigestFor(file: $this->file(bytes: 'replaced', etag: 'etag-2'), object: new ObjectEntity());
		} finally {
			$this->assertCount(1, $this->rows);
			$this->assertSame('file.digest-mismatch', $this->rows[0]->getAction());
			$this->assertContains('[PublishedDownloadGuard] The mismatch could not be marked for the operations console: table locked', $this->errors);
		}
	}

	public function testTheNightlyJobMarksAChangedFileAndLeavesAnUnchangedOne(): void {
		$changed = $this->record();
		$kept = $this->record(id: 8, fileId: 43);
		$this->runJob(records: [$changed, $kept], files: [42 => $this->file(bytes: 'replaced', etag: 'etag-2'), 43 => $this->file(bytes: self::BYTES, etag: 'etag-1')], objectFound: true);

		$this->assertSame([[7, hash('sha256', 'replaced')]], $this->flagged());
		$this->assertCount(1, $this->rows);
	}

	public function testTheNightlyJobMarksAGoneFileWhoseObjectIsGoneToo(): void {
		$this->runJob(records: [$this->record()], files: [], objectFound: false);

		$this->assertSame([[7, '']], $this->flagged());
		$this->assertSame([], $this->rows);
	}

	public function testTheNightlyJobStillAuditsWhenTheMarkFails(): void {
		$this->runJob(records: [$this->record()], files: [42 => $this->file(bytes: 'replaced', etag: 'etag-2')], objectFound: true, flagFails: true);

		$this->assertCount(1, $this->rows);
		$this->assertContains('[PublishedDigestVerifyJob] The mismatch could not be marked for the operations console: table locked', $this->errors);
	}

	/**
	 * The marks as [record id, current digest].
	 *
	 * @return list<array{0: int, 1: string}>
	 */
	private function flagged(): array {
		return array_map(static fn (array $flag): array => [$flag[0], $flag[2]], $this->flags);
	}

	/**
	 * Run the nightly job over these records.
	 *
	 * @param list<FileDigest>      $records     The open records.
	 * @param array<int, File>      $files       The files by id.
	 * @param bool                  $objectFound Whether the object of a file is found.
	 * @param bool                  $flagFails   Whether writing the mark fails.
	 *
	 * @return void
	 */
	private function runJob(array $records, array $files, bool $objectFound, bool $flagFails = false): void {
		$digests = $this->digests(flagFails: $flagFails);
		$digests->method('findOpenAfter')->willReturnCallback(
			static fn (int $afterId): array => array_values(array_filter($records, static fn (FileDigest $r): bool => $r->getId() > $afterId))
		);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getFirstNodeById')->willReturnCallback(static fn (int $id) => ($files[$id] ?? null));

		$objects = $this->createMock(MagicObjectFinderInterface::class);
		$objects->method('findAcrossAllSources')->willReturnCallback(
			static fn (): array => ['object' => ($objectFound === true ? new ObjectEntity() : null), 'register' => null, 'schema' => null]
		);

		$job = new PublishedDigestVerifyJob($this->createMock(ITimeFactory::class), $digests, $this->service(digests: $digests), $root, $objects, $this->logger());
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	/**
	 * The digest mapper, recording the marks and the open record of file 42.
	 *
	 * @param bool $flagFails Whether writing the mark fails.
	 *
	 * @return FileDigestMapper&\PHPUnit\Framework\MockObject\MockObject The mapper.
	 */
	private function digests(bool $flagFails = false): FileDigestMapper {
		$digests = $this->createMock(FileDigestMapper::class);
		$digests->method('findOpenForFile')->willReturn($this->record());
		$digests->method('flagMismatch')->willReturnCallback(
			function (int $id, DateTime $at, string $current) use ($flagFails): int {
				if ($flagFails === true) {
					throw new RuntimeException('table locked');
				}

				$this->flags[] = [$id, $at, $current];
				return 1;
			}
		);

		return $digests;
	}

	private function service(FileDigestMapper $digests): PublishedDigestService {
		$auditTrails = $this->createMock(AuditTrailMapperInterface::class);
		$auditTrails->method('buildEntry')->willReturnCallback(
			static function ($change): AuditTrail {
				$row = new AuditTrail();
				$row->setAction($change->action);
				$row->setChanged([]);
				return $row;
			}
		);
		$auditTrails->method('insertAuditTrails')->willReturnCallback(
			function (array $entries): array {
				array_push($this->rows, ...$entries);
				return $entries;
			}
		);

		return new PublishedDigestService(
			$digests,
			$auditTrails,
			$this->createMock(IFilesMetadataManager::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		return $logger;
	}

	private function record(int $id = 7, int $fileId = 42): FileDigest {
		$record = new FileDigest();
		$record->setId($id);
		$record->setFileId($fileId);
		$record->setObjectUuid('object-1');
		$record->setSha256(hash('sha256', self::BYTES));
		$record->setSize(strlen(self::BYTES));
		$record->setEtag('etag-1');
		$record->setAuditTrailUuid('audit-1');

		return $record;
	}

	private function file(string $bytes, string $etag): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getEtag')->willReturn($etag);
		$file->method('getSize')->willReturn(strlen($bytes));
		$file->method('fopen')->willReturnCallback(
			static function () use ($bytes) {
				$handle = fopen('php://memory', 'r+');
				fwrite($handle, $bytes);
				rewind($handle);
				return $handle;
			}
		);

		return $file;
	}
}//end class
