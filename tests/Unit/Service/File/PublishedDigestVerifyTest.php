<?php

/**
 * PublishedDigestService::verify() and recordMismatch().
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

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\FileDigest;
use OCA\OpenRegister\Db\FileDigestMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\PublishedDigestService;
use OCP\Files\File;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PublishedDigestVerifyTest extends TestCase {

	private const BYTES = 'the published decision';

	/**
	 * Audit rows written.
	 *
	 * @var AuditTrail[]
	 */
	private array $written = [];

	private function service(): PublishedDigestService {
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

		return new PublishedDigestService(
			$this->createMock(FileDigestMapper::class),
			$auditTrails,
			$this->createMock(IFilesMetadataManager::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	private function record(): FileDigest {
		$record = new FileDigest();
		$record->setFileId(42);
		$record->setObjectUuid('object-1');
		$record->setSha256(hash('sha256', self::BYTES));
		$record->setSize(strlen(self::BYTES));
		$record->setEtag('etag-1');
		$record->setAuditTrailUuid('sealed-row');
		return $record;
	}

	private function file(string $bytes, string $etag, ?int $size=null): File {
		$file = $this->createMock(File::class);
		$file->method('getEtag')->willReturn($etag);
		$file->method('getSize')->willReturn($size ?? strlen($bytes));
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

	public function testAnUnchangedEtagAndSizeSkipTheRecompute(): void {
		$file = $this->file(bytes: self::BYTES, etag: 'etag-1');
		$file->expects($this->never())->method('fopen');

		$result = $this->service()->verify(record: $this->record(), file: $file);

		$this->assertSame(PublishedDigestService::IDENTICAL, $result['verdict']);
		$this->assertSame(hash('sha256', self::BYTES), $result['current']);
	}

	public function testAMovedEtagWithTheSameBytesIsIdentical(): void {
		$result = $this->service()->verify(record: $this->record(), file: $this->file(bytes: self::BYTES, etag: 'etag-2'));

		$this->assertSame(['verdict' => 'identical', 'recorded' => hash('sha256', self::BYTES), 'current' => hash('sha256', self::BYTES)], $result);
	}

	public function testReplacedBytesAreChanged(): void {
		$result = $this->service()->verify(record: $this->record(), file: $this->file(bytes: 'other bytes', etag: 'etag-2'));

		$this->assertSame(PublishedDigestService::CHANGED, $result['verdict']);
		$this->assertSame(hash('sha256', 'other bytes'), $result['current']);
	}

	public function testTheSameEtagWithAnotherSizeIsRecomputed(): void {
		$result = $this->service()->verify(record: $this->record(), file: $this->file(bytes: 'other bytes', etag: 'etag-1'));

		$this->assertSame(PublishedDigestService::CHANGED, $result['verdict']);
	}

	public function testTheMismatchIsAnAuditRowNamingBothDigests(): void {
		$object = new ObjectEntity();
		$object->setUuid('object-1');

		$this->service()->recordMismatch(object: $object, record: $this->record(), current: 'abc');

		$this->assertCount(1, $this->written);
		$this->assertSame('file.digest-mismatch', $this->written[0]->getAction());
		$this->assertSame(
			['fileId' => 42, 'objectUuid' => 'object-1', 'recorded' => hash('sha256', self::BYTES), 'current' => 'abc', 'publishedAuditRow' => 'sealed-row'],
			$this->written[0]->getChanged()
		);
	}
}
