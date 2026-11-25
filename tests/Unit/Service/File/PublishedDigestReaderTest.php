<?php

/**
 * The read side of a publication's seal (REQ-PFD-002).
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

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\FileDigest;
use OCA\OpenRegister\Db\FileDigestMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\File\PublishedDigestReader;
use OCA\OpenRegister\Service\File\PublishedDigestService;
use OCP\Files\File;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for {@see PublishedDigestReader}.
 */
#[CoversClass(PublishedDigestReader::class)]
class PublishedDigestReaderTest extends TestCase {

	private const BYTES = 'the published decision';

	/**
	 * The audit uuids the trail was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * An unchanged file is identical, and the answer names the row that sealed it.
	 *
	 * @return void
	 */
	public function testAnUnchangedFileIsIdenticalWithItsSeal(): void {
		$reader = $this->reader(record: $this->record());

		$answer = $reader->verify(file: $this->file(bytes: self::BYTES, etag: 'etag-1'));

		self::assertSame(
			[
				'recorded' => hash('sha256', self::BYTES),
				'current' => hash('sha256', self::BYTES),
				'verdict' => 'identical',
				'auditRow' => ['uuid' => 'audit-1', 'hash' => 'h-1', 'previousHash' => 'h-0'],
			],
			$answer
		);
		self::assertSame(['audit-1'], $this->asked);
	}//end testAnUnchangedFileIsIdenticalWithItsSeal()

	/**
	 * Replaced bytes are reported as changed, with both digests.
	 *
	 * @return void
	 */
	public function testReplacedBytesAreChanged(): void {
		$answer = $this->reader(record: $this->record())->verify(file: $this->file(bytes: 'other bytes', etag: 'etag-2'));

		self::assertSame('changed', $answer['verdict']);
		self::assertSame(hash('sha256', self::BYTES), $answer['recorded']);
		self::assertSame(hash('sha256', 'other bytes'), $answer['current']);
	}//end testReplacedBytesAreChanged()

	/**
	 * A file with no open record is not published and has nothing to verify.
	 *
	 * @return void
	 */
	public function testAnUnpublishedFileHasNoVerdict(): void {
		$reader = $this->reader(record: null);

		self::assertNull($reader->verify(file: $this->file(bytes: self::BYTES, etag: 'etag-1')));
		self::assertNull($reader->publishedOf(fileId: 42));
	}//end testAnUnpublishedFileHasNoVerdict()

	/**
	 * The published block carries the digest, the size, when, and the sealing row.
	 *
	 * @return void
	 */
	public function testThePublishedBlockCarriesTheSeal(): void {
		self::assertSame(
			[
				'sha256' => hash('sha256', self::BYTES),
				'size' => strlen(self::BYTES),
				'publishedAt' => '2025-11-24T10:00:00+00:00',
				'sealedBy' => ['uuid' => 'audit-1', 'hash' => 'h-1'],
			],
			$this->reader(record: $this->record())->publishedOf(fileId: 42)
		);
	}//end testThePublishedBlockCarriesTheSeal()

	/**
	 * An unreadable trail leaves the seal's hashes empty rather than failing the answer.
	 *
	 * @return void
	 */
	public function testAnUnreadableTrailKeepsTheUuid(): void {
		$reader = $this->reader(record: $this->record(), trailFails: true);

		self::assertSame(['uuid' => 'audit-1', 'hash' => null], $reader->publishedOf(fileId: 42)['sealedBy']);
	}//end testAnUnreadableTrailKeepsTheUuid()

	/**
	 * The reader over one record.
	 *
	 * @param FileDigest|null $record     The open record, or none.
	 * @param bool            $trailFails Whether reading the audit trail throws.
	 *
	 * @return PublishedDigestReader The reader.
	 */
	private function reader(?FileDigest $record, bool $trailFails = false): PublishedDigestReader {
		$digests = $this->createMock(FileDigestMapper::class);
		$digests->method('findOpenForFile')->with(42)->willReturn($record);

		$auditTrails = $this->createMock(AuditTrailMapperInterface::class);
		$auditTrails->method('findTrail')->willReturnCallback(
			function (ListQuery $query) use ($trailFails): array {
				if ($trailFails === true) {
					throw new RuntimeException('trail unavailable');
				}

				$this->asked[] = $query->filters['uuid'];
				$row = new AuditTrail();
				$row->setUuid($query->filters['uuid']);
				$row->setHash('h-1');
				$row->setPreviousHash('h-0');

				return [$row];
			}
		);

		$service = new PublishedDigestService(
			$digests,
			$auditTrails,
			$this->createMock(IFilesMetadataManager::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class)
		);

		return new PublishedDigestReader(digests: $digests, service: $service);
	}//end reader()

	/**
	 * The record of a publication a year ago.
	 *
	 * @return FileDigest The record.
	 */
	private function record(): FileDigest {
		$record = new FileDigest();
		$record->setFileId(42);
		$record->setSha256(hash('sha256', self::BYTES));
		$record->setSize(strlen(self::BYTES));
		$record->setEtag('etag-1');
		$record->setPublishedAt(new DateTime('2025-11-24T10:00:00+00:00'));
		$record->setAuditTrailUuid('audit-1');

		return $record;
	}//end record()

	/**
	 * A file with these bytes.
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
		$file->method('fopen')->willReturnCallback(static function () use ($bytes) {
			$handle = fopen('php://memory', 'r+');
			fwrite($handle, $bytes);
			rewind($handle);

			return $handle;
		});

		return $file;
	}//end file()
}//end class
