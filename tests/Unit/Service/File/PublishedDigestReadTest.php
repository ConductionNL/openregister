<?php

/**
 * A published file is checked against its seal when it is downloaded (REQ-PFD-002).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\FileDigest;
use OCA\OpenRegister\Db\FileDigestMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\PublishedDigestService;
use OCA\OpenRegister\Service\File\PublishedDownloadGuard;
use OCA\OpenRegister\Service\File\PublishedFileChangedException;
use OCP\Files\File;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for {@see PublishedDownloadGuard}.
 */
#[CoversClass(PublishedDownloadGuard::class)]
#[CoversClass(PublishedFileChangedException::class)]
#[UsesClass(PublishedDigestService::class)]
#[UsesClass(FileDigest::class)]
class PublishedDigestReadTest extends TestCase {

	private const BYTES = 'the published decision';

	/**
	 * Audit rows written.
	 *
	 * @var list<AuditTrail>
	 */
	private array $rows = [];

	/**
	 * Errors logged for operations.
	 *
	 * @var list<array{message: string, context: array<string, mixed>}>
	 */
	private array $errors = [];

	/**
	 * How often the file was opened to be hashed.
	 *
	 * @var integer
	 */
	private int $opened = 0;

	/**
	 * A published file that still matches is served with its digest in `Repr-Digest`.
	 *
	 * @return void
	 */
	public function testTheDigestHeaderIsSent(): void {
		$value = $this->guard(record: $this->record())->reprDigestFor(file: $this->file(bytes: self::BYTES, etag: 'etag-1'), object: new ObjectEntity());

		self::assertSame('sha-256=:' . base64_encode(hash('sha256', self::BYTES, true)) . ':', $value);
		self::assertSame([], $this->rows);
		self::assertSame([], $this->errors);
	}//end testTheDigestHeaderIsSent()

	/**
	 * An unchanged etag and size mean unchanged bytes: the file is not read again.
	 *
	 * @return void
	 */
	public function testAnUnchangedEtagSkipsTheRecompute(): void {
		$this->guard(record: $this->record())->reprDigestFor(file: $this->file(bytes: self::BYTES, etag: 'etag-1'), object: null);

		self::assertSame(0, $this->opened);
	}//end testAnUnchangedEtagSkipsTheRecompute()

	/**
	 * A new etag with the same bytes is recomputed and still served.
	 *
	 * @return void
	 */
	public function testANewEtagOverTheSameBytesIsRecomputedAndServed(): void {
		$value = $this->guard(record: $this->record())->reprDigestFor(file: $this->file(bytes: self::BYTES, etag: 'etag-2'), object: null);

		self::assertSame(1, $this->opened);
		self::assertSame(PublishedDownloadGuard::reprDigest(sha256: hash('sha256', self::BYTES)), $value);
	}//end testANewEtagOverTheSameBytesIsRecomputedAndServed()

	/**
	 * Bytes replaced behind the publication are refused, audited and alerted.
	 *
	 * @return void
	 */
	public function testAChangedFileIsRefusedAndAlerted(): void {
		$object = new ObjectEntity();
		$object->setUuid('object-1');

		try {
			$this->guard(record: $this->record())->reprDigestFor(file: $this->file(bytes: 'replaced bytes', etag: 'etag-2'), object: $object);
			self::fail('A changed published file must not be served.');
		} catch (PublishedFileChangedException $e) {
			self::assertSame(42, $e->fileId);
		}

		self::assertCount(1, $this->rows, 'One file.digest-mismatch row.');
		self::assertSame(PublishedDigestService::MISMATCH_ACTION, $this->rows[0]->getAction());
		self::assertSame(hash('sha256', self::BYTES), $this->rows[0]->getChanged()['recorded']);
		self::assertSame(hash('sha256', 'replaced bytes'), $this->rows[0]->getChanged()['current']);
		self::assertSame('audit-1', $this->rows[0]->getChanged()['publishedAuditRow']);
		self::assertCount(1, $this->errors, 'Operations is alerted.');
		self::assertSame(42, $this->errors[0]['context']['fileId']);
	}//end testAChangedFileIsRefusedAndAlerted()

	/**
	 * The refusal stands even when the audit trail cannot be written.
	 *
	 * @return void
	 */
	public function testAFailingAuditWriteStillRefuses(): void {
		$this->expectException(PublishedFileChangedException::class);

		try {
			$this->guard(record: $this->record(), auditFails: true)
				->reprDigestFor(file: $this->file(bytes: 'replaced bytes', etag: 'etag-2'), object: new ObjectEntity());
		} finally {
			self::assertCount(2, $this->errors, 'The mismatch and the failed audit write are both logged.');
		}
	}//end testAFailingAuditWriteStillRefuses()

	/**
	 * A file that is not published passes without a header or a read.
	 *
	 * @return void
	 */
	public function testAnUnpublishedFileIsNotChecked(): void {
		self::assertNull($this->guard(record: null)->reprDigestFor(file: $this->file(bytes: 'x', etag: 'e'), object: null));
		self::assertSame(0, $this->opened);
	}//end testAnUnpublishedFileIsNotChecked()

	/**
	 * The guard over one record.
	 *
	 * @param FileDigest|null $record     The open record, or none.
	 * @param bool            $auditFails Whether writing the audit row throws.
	 *
	 * @return PublishedDownloadGuard The guard.
	 */
	private function guard(?FileDigest $record, bool $auditFails = false): PublishedDownloadGuard {
		$digests = $this->createMock(FileDigestMapper::class);
		$digests->method('findOpenForFile')->with(42)->willReturn($record);

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
			function (array $entries) use ($auditFails): array {
				if ($auditFails === true) {
					throw new RuntimeException('trail unavailable');
				}

				array_push($this->rows, ...$entries);
				return $entries;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = ['message' => $message, 'context' => $context];
			}
		);

		$service = new PublishedDigestService(
			$digests,
			$auditTrails,
			$this->createMock(IFilesMetadataManager::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class)
		);

		return new PublishedDownloadGuard(digests: $digests, service: $service, logger: $logger);
	}//end guard()

	/**
	 * The record of a publication a year ago.
	 *
	 * @return FileDigest The record.
	 */
	private function record(): FileDigest {
		$record = new FileDigest();
		$record->setFileId(42);
		$record->setObjectUuid('object-1');
		$record->setSha256(hash('sha256', self::BYTES));
		$record->setSize(strlen(self::BYTES));
		$record->setEtag('etag-1');
		$record->setPublishedAt(new DateTime('2025-11-24T10:00:00+00:00'));
		$record->setAuditTrailUuid('audit-1');

		return $record;
	}//end record()

	/**
	 * A file with these bytes, counting how often it is opened.
	 *
	 * @param string $bytes The bytes.
	 * @param string $etag  The etag.
	 *
	 * @return File The file.
	 */
	private function file(string $bytes, string $etag): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getEtag')->willReturn($etag);
		$file->method('getSize')->willReturn(strlen($bytes));
		$file->method('fopen')->willReturnCallback(function () use ($bytes) {
			$this->opened++;
			$handle = fopen('php://memory', 'r+');
			fwrite($handle, $bytes);
			rewind($handle);

			return $handle;
		});

		return $file;
	}//end file()
}//end class
