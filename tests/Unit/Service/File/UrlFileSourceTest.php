<?php

/**
 * UrlFileSource: the fetch, the upload checks on every byte, and the provenance kept on the file.
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
 * @spec openspec/changes/files-create-from-url/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Service\File\FetchedFile;
use OCA\OpenRegister\Service\File\FileValidationHandler;
use OCA\OpenRegister\Service\File\UrlFetchRefused;
use OCA\OpenRegister\Service\File\UrlFileFetcher;
use OCA\OpenRegister\Service\File\UrlFileSource;
use OCA\OpenRegister\Service\File\UrlStreamOpener;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The opener (HTTP), the metadata manager, the clock and the logger are stubs;
 * the fetcher, the address guard and the upload checks are the real ones.
 *
 * @covers \OCA\OpenRegister\Service\File\UrlFileSource
 * @covers \OCA\OpenRegister\Service\File\FetchedFile
 * @uses   \OCA\OpenRegister\Service\File\UrlFileFetcher
 * @uses   \OCA\OpenRegister\Service\File\UrlFetchRefused
 * @uses   \OCA\OpenRegister\Service\File\FileValidationHandler
 * @uses   \OCA\OpenRegister\Service\File\ExecutableContentDetector
 * @uses   \OCA\OpenRegister\Service\File\DangerousExtensionLists
 * @uses   \OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService
 * @uses   \OCA\OpenRegister\Service\SecurityService
 * @uses   \OCA\OpenRegister\Service\Security\SafeFetchUrl
 * @uses   \OCA\OpenRegister\Service\Security\OutboundAddressGuard
 */
class UrlFileSourceTest extends TestCase {

	/**
	 * A public address, so the guard needs no name lookup.
	 */
	private const PUBLIC_URL = 'https://93.184.215.14/besluit.pdf';

	/**
	 * The opener.
	 *
	 * @var UrlStreamOpener&MockObject
	 */
	private UrlStreamOpener&MockObject $opener;

	/**
	 * The metadataManager.
	 *
	 * @var IFilesMetadataManager&MockObject
	 */
	private IFilesMetadataManager&MockObject $metadataManager;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->opener = $this->createMock(UrlStreamOpener::class);
		$this->metadataManager = $this->createMock(IFilesMetadataManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function source(): UrlFileSource {
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime('2026-10-05T09:30:00', new DateTimeZone('UTC')));

		return new UrlFileSource(
			new UrlFileFetcher($this->opener),
			new FileValidationHandler($this->createMock(LoggerInterface::class)),
			$this->metadataManager,
			$clock,
			$this->logger,
		);
	}

	private function serve(int $status, string $body): void {
		$stream = fopen('php://memory', 'w+b');
		fwrite($stream, $body);
		rewind($stream);
		$this->opener->expects($this->once())->method('open')->with(self::PUBLIC_URL)->willReturn(['stream' => $stream, 'status' => $status]);
	}

	public function testTheBytesComeWithWhereAndWhenAndTheirHash(): void {
		$this->serve(200, '%PDF-1.4 besluit');

		$fetched = $this->source()->fetch(self::PUBLIC_URL, 'besluit.pdf');

		$this->assertSame('%PDF-1.4 besluit', $fetched->bytes);
		$this->assertSame(
			[
				'sourceUrl' => self::PUBLIC_URL,
				'fetchedAt' => '2026-10-05T09:30:00+00:00',
				'sha256'    => hash('sha256', '%PDF-1.4 besluit'),
			],
			$fetched->metadata()
		);
	}

	public function testTheStreamCarriesTheBytesUnchanged(): void {
		// Valid base64: written as content it would be decoded, as a stream it is not.
		$fetched = new FetchedFile('aGVsbG8=', self::PUBLIC_URL, '2026-10-05T09:30:00+00:00', 'x');

		$stream = $fetched->stream();

		$this->assertSame('aGVsbG8=', stream_get_contents($stream));
	}

	public function testExecutableBytesAreRefusedLikeAnUpload(): void {
		$this->serve(200, "\x7fELF\x02\x01\x01" . str_repeat("\x00", 32));

		$this->expectException(\Exception::class);
		$this->expectExceptionMessageMatches('/executable/i');

		$this->source()->fetch(self::PUBLIC_URL, 'besluit.pdf');
	}

	public function testADangerousNameIsRefusedLikeAnUpload(): void {
		$this->serve(200, 'echo hello');

		$this->expectException(\Exception::class);

		$this->source()->fetch(self::PUBLIC_URL, 'run.sh');
	}

	public function testARefusedFetchReachesTheCaller(): void {
		$this->opener->expects($this->never())->method('open');

		try {
			$this->source()->fetch('http://169.254.169.254/latest/meta-data', 'x.pdf');
			$this->fail('the fetch should have been refused');
		} catch (UrlFetchRefused $refusal) {
			$this->assertSame(UrlFetchRefused::REFUSED_ADDRESS, $refusal->reason);
		}
	}

	public function testTheProvenanceIsKeptOnTheFile(): void {
		$metadata = $this->createMock(IFilesMetadata::class);
		$this->metadataManager->expects($this->once())->method('getMetadata')->with(42, true)->willReturn($metadata);
		$stored = [];
		$metadata->expects($this->exactly(3))->method('setString')->willReturnCallback(
			function (string $key, string $value) use (&$stored, $metadata) {
				$stored[$key] = $value;
				return $metadata;
			}
		);
		$this->metadataManager->expects($this->once())->method('saveMetadata')->with($metadata);

		$kept = $this->source()->remember(42, new FetchedFile('b', self::PUBLIC_URL, '2026-10-05T09:30:00+00:00', 'abc'));

		$this->assertTrue($kept);
		$this->assertSame(
			[
				UrlFileSource::KEY_SOURCE_URL => self::PUBLIC_URL,
				UrlFileSource::KEY_FETCHED_AT => '2026-10-05T09:30:00+00:00',
				UrlFileSource::KEY_SHA256     => 'abc',
			],
			$stored
		);
	}

	public function testAFailedMetadataWriteIsLoggedNotThrown(): void {
		$this->metadataManager->method('getMetadata')->willThrowException(new \RuntimeException('no metadata'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[UrlFileSource] Could not store where a fetched file came from',
			['fileId' => 42, 'error' => 'no metadata']
		);

		$this->assertFalse($this->source()->remember(42, new FetchedFile('b', self::PUBLIC_URL, 'now', 'abc')));
	}

	public function testTheCeilingIsTheLowerPhpUploadLimit(): void {
		$upload = ini_get('upload_max_filesize');
		$post = ini_get('post_max_size');
		$expected = UrlFileSource::DEFAULT_CEILING_BYTES;
		$limits = array_filter(
			array_map(
				static fn (string $v): ?int => \OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService::toBytes($v),
				[(string)$upload, (string)$post]
			),
			static fn (?int $b): bool => $b !== null && $b > 0
		);
		if ($limits !== []) {
			$expected = min($limits);
		}

		$this->assertSame($expected, UrlFileSource::ceilingBytes());
		$this->assertGreaterThan(0, UrlFileSource::ceilingBytes());
	}
}
