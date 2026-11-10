<?php

/**
 * ManifestControllerGoldenTest
 *
 * Golden test pinning how the manifest endpoint loads a bundled manifest:
 * each not-found reason and its log level, the ETag and cache header, and
 * the enrichment failure body.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ManifestController;
use OCA\OpenRegister\Service\ManifestService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ManifestControllerFactory;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\ManifestController
 */
class ManifestControllerGoldenTest extends TestCase {

	private ManifestService&MockObject $manifestService;

	private IAppManager&MockObject $appManager;

	private LoggerInterface&MockObject $logger;

	private ManifestController $controller;

	private string $appDir;

	protected function setUp(): void {
		$this->manifestService = $this->createMock(ManifestService::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->controller = ManifestControllerFactory::create(
			$this->createMock(IRequest::class),
			$this->manifestService,
			$this->appManager,
			$this->logger
		);
		$this->appDir = sys_get_temp_dir() . '/manifest_golden_' . uniqid('', true);
		mkdir($this->appDir . '/src', 0777, true);
		$this->appManager->method('getAppPath')->willReturnCallback(
			fn (string $appId): string => $appId === 'gone'
				? throw new AppPathNotFoundException('no such app')
				: $this->appDir
		);
	}

	protected function tearDown(): void {
		@unlink($this->appDir . '/src/manifest.json');
		@rmdir($this->appDir . '/src');
		@rmdir($this->appDir);
	}

	private function writeManifest(string $raw): void {
		file_put_contents($this->appDir . '/src/manifest.json', $raw);
	}

	public function testAppIdWithADotIsA400(): void {
		$this->manifestService->expects($this->never())->method('getEnrichedManifest');

		$response = $this->controller->index('my.app');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Invalid app ID.'], $response->getData());
	}

	public function testUnknownAppIsA404AndLogsDebug(): void {
		$this->logger->expects($this->once())
			->method('debug')
			->with('[ManifestController] App "gone" path not found: no such app');

		$response = $this->controller->index('gone');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Manifest not found for app "gone".'], $response->getData());
	}

	public function testMissingManifestFileIsA404AndLogsDebug(): void {
		$this->logger->expects($this->once())
			->method('debug')
			->with('[ManifestController] manifest.json not readable at "' . $this->appDir . '/src/manifest.json"');

		$response = $this->controller->index('My_App-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Manifest not found for app "My_App-1".'], $response->getData());
	}

	public function testInvalidOrNonObjectJsonIsA404AndLogsAWarning(): void {
		$this->logger->expects($this->exactly(3))
			->method('warning')
			->with('[ManifestController] manifest.json for "host" is invalid JSON');
		$this->manifestService->expects($this->never())->method('getEnrichedManifest');

		foreach (['{broken', '42', ''] as $raw) {
			$this->writeManifest($raw);
			$response = $this->controller->index('host');
			$this->assertSame(404, $response->getStatus());
		}
	}

	public function testValidManifestIsEnrichedWithETagAndCacheHeader(): void {
		$this->writeManifest('{"version":"1.0.0","menu":[]}');
		$enriched = ['version' => '1.0.0', 'menu' => [], 'runtime' => ['user' => null]];
		$this->manifestService->expects($this->once())
			->method('getEnrichedManifest')
			->with(['version' => '1.0.0', 'menu' => []])
			->willReturn($enriched);

		$response = $this->controller->index('host');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($enriched, $response->getData());
		$this->assertSame(hash(algo: 'xxh128', data: '{"version":"1.0.0","menu":[],"runtime":{"user":null}}'), $response->getETag());
		$this->assertSame('private, no-cache', $response->getHeaders()['Cache-Control']);
	}

	public function testEnrichmentFailureIsA500AndLogsAnError(): void {
		$this->writeManifest('{"version":"1.0.0"}');
		$this->manifestService->method('getEnrichedManifest')->willThrowException(new RuntimeException('kaput'));
		$this->logger->expects($this->once())
			->method('error')
			->with('[ManifestController] Enrichment failed for app "host": kaput');

		$response = $this->controller->index('host');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Internal server error.'], $response->getData());
	}
}
