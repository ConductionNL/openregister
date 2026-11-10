<?php

/**
 * Tests for BundledManifestFile::decode: a valid object, a missing file,
 * invalid JSON and JSON that is not an object or array.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Observability;

use OCA\OpenRegister\AppHost\Observability\BundledManifestFile;
use OCA\OpenRegister\AppHost\Observability\ManifestLookup;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Reads manifests from a temporary app directory.
 */
class BundledManifestFileTest extends TestCase {

	private string $appPath = '';

	protected function setUp(): void {
		$this->appPath = sys_get_temp_dir().'/bmf-a2-'.getmypid();
		mkdir($this->appPath.'/src', 0777, true);
	}

	protected function tearDown(): void {
		@unlink($this->appPath.'/src/manifest.json');
		@rmdir($this->appPath.'/src');
		@rmdir($this->appPath);
	}

	private function write(string $contents): void {
		file_put_contents($this->appPath.'/src/manifest.json', $contents);
	}

	public function testAValidObjectIsDecodedWithoutWarning(): void {
		$this->write('{"observability":{"metrics":[{"name":"m"}]}}');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->assertSame(
			['observability' => ['metrics' => [['name' => 'm']]]],
			BundledManifestFile::decode($this->appPath, 'myapp', $logger, 'Tag')
		);
	}

	public function testAJsonArrayIsAcceptedAsDecoded(): void {
		$this->write('[]');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->assertSame([], BundledManifestFile::decode($this->appPath, 'myapp', $logger, 'Tag'));
	}

	public function testAMissingManifestIsNullWithoutWarning(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->assertNull(BundledManifestFile::decode($this->appPath, 'myapp', $logger, 'Tag'));
	}

	public function testInvalidJsonIsNullAndWarnsWithTagAndAppId(): void {
		$this->write('{"broken":');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[AppHost\\Scheduling] manifest.json for "myapp" is invalid JSON',
			$this->callback(static fn (array $context): bool => isset($context['file'], $context['line']) === true)
		);

		$this->assertNull(BundledManifestFile::decode($this->appPath, 'myapp', $logger, 'AppHost\\Scheduling'));
	}

	public function testAScalarJsonDocumentIsNullAndWarns(): void {
		$this->write('42');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[Tag] manifest.json for "other" is invalid JSON',
			$this->anything()
		);

		$this->assertNull(BundledManifestFile::decode($this->appPath, 'other', $logger, 'Tag'));
	}

	public function testDecodeForReadsTheLookup(): void {
		$this->write('{"broken":');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[Tag] manifest.json for "myapp" is invalid JSON',
			$this->anything()
		);

		$this->assertNull(BundledManifestFile::decodeFor(new ManifestLookup('myapp', $this->appPath, 'Tag'), $logger));

		$this->write('{"a":1}');
		$this->assertSame(['a' => 1], BundledManifestFile::decodeFor(new ManifestLookup('myapp', $this->appPath, 'Tag'), $this->createMock(LoggerInterface::class)));
	}
}//end class
