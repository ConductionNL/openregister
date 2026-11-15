<?php

/**
 * FileChecksum: the SHA-256 recorded with a file, and the fixity check against it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/capability-rows-stated-requirements/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\File\FileChecksum;
use OCP\Files\File;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(FileChecksum::class)]
final class FileChecksumTest extends TestCase {
	private IFilesMetadataManager&MockObject $manager;
	private LoggerInterface&MockObject $logger;

	/**
	 * The metadata values stored, by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	protected function setUp(): void {
		$this->stored = [];
		$metadata = $this->createMock(IFilesMetadata::class);
		$metadata->method('setString')->willReturnCallback(
			function (string $key, string $value) use ($metadata): IFilesMetadata {
				$this->stored[$key] = $value;
				return $metadata;
			}
		);
		$metadata->method('hasKey')->willReturnCallback(fn (string $key): bool => isset($this->stored[$key]));
		$metadata->method('getString')->willReturnCallback(fn (string $key): string => $this->stored[$key]);
		$this->manager = $this->createMock(IFilesMetadataManager::class);
		$this->manager->method('getMetadata')->willReturn($metadata);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function checksum(): FileChecksum {
		return new FileChecksum($this->manager, $this->logger);
	}

	/**
	 * A file whose content is the given bytes, read freshly on every open.
	 */
	private function fileWith(string &$content, int $id=42): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('fopen')->willReturnCallback(
			static function (string $mode) use (&$content) {
				$handle = fopen('php://memory', 'w+');
				fwrite($handle, $content);
				rewind($handle);
				return $handle;
			}
		);

		return $file;
	}

	public function testAnUploadedFileCarriesItsSha256(): void {
		$content = 'Besluit op bezwaar, versie 1';
		$this->manager->expects(self::once())->method('saveMetadata');

		self::assertTrue($this->checksum()->record($this->fileWith($content)));

		self::assertSame(['openregister-sha256' => hash('sha256', $content)], $this->stored);
	}

	public function testTheKeyIsTheOneAFetchedFileCarries(): void {
		self::assertSame('openregister-sha256', FileChecksum::METADATA_KEY);
	}

	public function testAnUnchangedFileChecksAsUnchanged(): void {
		$content = 'Besluit op bezwaar, versie 1';
		$file = $this->fileWith($content);
		$this->checksum()->record($file);

		self::assertSame(
			['status' => 'unchanged', 'recorded' => hash('sha256', $content), 'current' => hash('sha256', $content)],
			$this->checksum()->check($file)
		);
	}

	public function testChangedContentIsReportedAsAMismatch(): void {
		$content = 'Besluit op bezwaar, versie 1';
		$file = $this->fileWith($content);
		$this->checksum()->record($file);
		$recorded = hash('sha256', $content);

		$content = 'Besluit op bezwaar, versie 1 (aangepast buiten de app)';

		self::assertSame(
			['status' => 'changed', 'recorded' => $recorded, 'current' => hash('sha256', $content)],
			$this->checksum()->check($file)
		);
	}

	public function testAStoredChecksumIsComparedRegardlessOfCase(): void {
		$content = 'x';
		$this->stored['openregister-sha256'] = ' ' . strtoupper(hash('sha256', $content)) . ' ';

		self::assertSame('unchanged', $this->checksum()->check($this->fileWith($content))['status']);
	}

	public function testAFileWithoutAChecksumIsUnrecorded(): void {
		$content = 'uploaded before checksums existed';

		self::assertSame(
			['status' => 'unrecorded', 'recorded' => '', 'current' => hash('sha256', $content)],
			$this->checksum()->check($this->fileWith($content))
		);
	}

	public function testAContentLargerThanOneChunkIsHashedWhole(): void {
		$content = str_repeat('0123456789abcdef', 200000);

		self::assertSame(hash('sha256', $content), $this->checksum()->digestOf($this->fileWith($content)));
	}

	public function testAFileThatCannotBeReadIsLoggedAndNotRecorded(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(7);
		$file->method('fopen')->willReturn(false);
		$this->manager->expects(self::never())->method('saveMetadata');
		$this->logger->expects(self::once())->method('warning')->with(
			'[FileChecksum] Could not store the checksum of a file',
			['fileId' => 7, 'error' => 'The file cannot be opened for reading']
		);

		self::assertFalse($this->checksum()->record($file));
		self::assertSame([], $this->stored);
	}

	public function testACheckOnAnUnreadableFileThrows(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(7);
		$file->method('fopen')->willReturn(false);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The file cannot be opened for reading');

		$this->checksum()->check($file);
	}
}
