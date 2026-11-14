<?php
/**
 * Unit tests for BagExtractSource — a BAG extract source as a local file.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Bag
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Bag;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use InvalidArgumentException;
use OCA\OpenRegister\Service\Bag\BagExtractSource;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(BagExtractSource::class)]
final class BagExtractSourceTest extends TestCase {

	private function source(?IClient $client = null, string|false $temp = false): BagExtractSource {
		$http = $this->createMock(IClientService::class);
		$http->method('newClient')->willReturn($client ?? $this->createMock(IClient::class));
		$temps = $this->createMock(ITempManager::class);
		$temps->method('getTemporaryFile')->willReturn($temp);

		return new BagExtractSource($http, $temps);
	}

	public function testALocalFileIsUsedWhereItIsAndNeverRemoved(): void {
		$path = (string) tempnam(sys_get_temp_dir(), 'bag');
		$source = $this->source();

		$this->assertSame($path, $source->localPath(' ' . $path . ' '));
		$source->release($path, $path);
		$this->assertFileExists($path);
		unlink($path);
	}

	public function testAMissingFileIsRefusedByItsName(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot be read: absent.csv');

		$this->source()->localPath('/no/such/dir/absent.csv');
	}

	public function testNoSourceIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('bag_bulk_url');

		$this->source()->localPath('  ');
	}

	public function testAUrlIsDownloadedIntoATemporaryFileThatReleaseRemoves(): void {
		$temp = (string) tempnam(sys_get_temp_dir(), 'bag');
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('get')->with('https://bag.example/extract.csv', ['sink' => $temp, 'timeout' => 1800]);

		$source = $this->source($client, $temp);
		$this->assertSame($temp, $source->localPath('https://bag.example/extract.csv'));
		$source->release($temp, 'https://bag.example/extract.csv');
		$this->assertFileDoesNotExist($temp);
	}

	public function testAFailedDownloadRemovesItsFileAndSaysSoWithoutTheUrl(): void {
		$temp = (string) tempnam(sys_get_temp_dir(), 'bag');
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('connection refused to https://user:pw@bag.example'));

		try {
			$this->source($client, $temp)->localPath('https://user:pw@bag.example/extract.csv');
			$this->fail('expected a refusal');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('The BAG extract could not be downloaded.', $e->getMessage());
		}

		$this->assertFileDoesNotExist($temp);
	}

	public function testNoTemporaryFileIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('No temporary file');

		$this->source(null, false)->localPath('http://bag.example/extract.csv');
	}

	public function testOnlyHttpAndHttpsAreUrls(): void {
		$this->assertTrue(BagExtractSource::isUrl('HTTPS://bag.example/x'));
		$this->assertTrue(BagExtractSource::isUrl('http://bag.example/x'));
		$this->assertFalse(BagExtractSource::isUrl('file:///etc/passwd'));
		$this->assertFalse(BagExtractSource::isUrl('/srv/bag.csv'));
	}
}
