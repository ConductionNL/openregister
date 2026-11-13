<?php

/**
 * PublishedDigestVerifyJob: the daily check over the published files.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
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

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\PublishedDigestVerifyJob;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\FileDigest;
use OCA\OpenRegister\Db\FileDigestMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\PublishedDigestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PublishedDigestVerifyJobTest extends TestCase {

	/**
	 * Audit rows written.
	 *
	 * @var AuditTrail[]
	 */
	private array $written = [];

	/**
	 * Error messages logged, with their context.
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $errors = [];

	private function record(int $id, int $fileId, string $bytes): FileDigest {
		$record = new FileDigest();
		$record->setId($id);
		$record->setFileId($fileId);
		$record->setObjectUuid('object-'.$fileId);
		$record->setSha256(hash('sha256', $bytes));
		$record->setSize(strlen($bytes));
		$record->setEtag('etag-'.$fileId);
		return $record;
	}

	private function file(string $bytes, string $etag): File {
		$file = $this->createMock(File::class);
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

	/**
	 * Run the job over the given records and files.
	 *
	 * @param FileDigest[]           $records The open records.
	 * @param array<int, File|null>  $files   The file per file id.
	 *
	 * @return void
	 */
	private function runJob(array $records, array $files): void {
		$digests = $this->createMock(FileDigestMapper::class);
		$digests->method('findOpenAfter')->willReturnCallback(
			static fn (int $afterId, int $limit): array => array_values(array_filter($records, static fn (FileDigest $r): bool => $r->getId() > $afterId))
		);

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
				array_push($this->written, ...$entries);
				return $entries;
			}
		);
		$service = new PublishedDigestService(
			$digests,
			$auditTrails,
			$this->createMock(IFilesMetadataManager::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class)
		);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getFirstNodeById')->willReturnCallback(static fn (int $id) => ($files[$id] ?? null));

		$objects = $this->createMock(MagicObjectFinderInterface::class);
		$objects->method('findAcrossAllSources')->willReturnCallback(
			static function (string|int $identifier): array {
				$object = new ObjectEntity();
				$object->setUuid((string)$identifier);
				return ['object' => $object, 'register' => null, 'schema' => null];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->errors[] = [$message, $context];
			}
		);

		$job = new PublishedDigestVerifyJob($this->createMock(ITimeFactory::class), $digests, $service, $root, $objects, $logger);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testOneChangedAndOneUnchangedFile(): void {
		$this->runJob(
			records: [$this->record(1, 10, 'kept'), $this->record(2, 20, 'original')],
			files: [10 => $this->file('kept', 'etag-10'), 20 => $this->file('replaced', 'etag-20b')]
		);

		$this->assertCount(1, $this->written);
		$this->assertSame('file.digest-mismatch', $this->written[0]->getAction());
		$this->assertSame(20, $this->written[0]->getChanged()['fileId']);
		$this->assertSame(hash('sha256', 'replaced'), $this->written[0]->getChanged()['current']);
		$this->assertCount(1, $this->errors);
		$this->assertStringContainsString('the bytes changed since publication', $this->errors[0][0]);
		$this->assertSame(20, $this->errors[0][1]['fileId']);
	}

	public function testAGoneFileIsReported(): void {
		$this->runJob(records: [$this->record(1, 10, 'kept')], files: []);

		$this->assertSame('', $this->written[0]->getChanged()['current']);
		$this->assertStringContainsString('the file is gone', $this->errors[0][0]);
	}

	public function testAnUnreadableFileIsReportedAndTheNextIsStillChecked(): void {
		$unreadable = $this->createMock(File::class);
		$unreadable->method('getEtag')->willReturn('moved');
		$unreadable->method('fopen')->willThrowException(new \RuntimeException('storage offline'));

		$this->runJob(
			records: [$this->record(1, 10, 'a'), $this->record(2, 20, 'b')],
			files: [10 => $unreadable, 20 => $this->file('changed', 'etag-20b')]
		);

		$this->assertSame([10, 20], array_map(static fn (AuditTrail $row): int => $row->getChanged()['fileId'], $this->written));
		$this->assertStringContainsString('cannot be read', $this->errors[0][0]);
	}

	public function testItRunsDaily(): void {
		$job = new PublishedDigestVerifyJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(FileDigestMapper::class),
			$this->createMock(PublishedDigestService::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(MagicObjectFinderInterface::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(86400, (new \ReflectionProperty(\OCP\BackgroundJob\TimedJob::class, 'interval'))->getValue($job));
	}
}
