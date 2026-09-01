<?php

/**
 * Unit tests for ManifestController.
 *
 * @category Test
 * @package  Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ManifestController;
use OCA\OpenRegister\Service\ManifestService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ManifestController.
 */
class ManifestControllerTest extends TestCase {

	/**
	 * Manifest enrichment service mock.
	 *
	 * @var ManifestService&MockObject
	 */
	private ManifestService $manifestService;

	/**
	 * App manager mock (app path resolution).
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager $appManager;

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * PSR logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Controller under test.
	 *
	 * @var ManifestController
	 */
	private ManifestController $controller;

	/**
	 * Set up mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->manifestService = $this->createMock(originalClassName: ManifestService::class);
		$this->appManager = $this->createMock(originalClassName: IAppManager::class);
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->controller = new ManifestController(
			'openregister',
			$this->request,
			$this->manifestService,
			$this->appManager,
			$this->logger
		);
	}//end setUp()

	/**
	 * An invalid app ID (contains special chars) must return 400.
	 *
	 * @return void
	 */
	public function testInvalidAppIdReturnsBadRequest(): void {
		$result = $this->controller->index('../evil');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $result->getStatus());
	}//end testInvalidAppIdReturnsBadRequest()

	/**
	 * When the app manager cannot find the app path, return 404.
	 *
	 * @return void
	 */
	public function testUnknownAppReturnsNotFound(): void {
		$this->appManager
			->method('getAppPath')
			->willThrowException(new \Exception('App not found'));

		$result = $this->controller->index('nonexistent-app');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $result->getStatus());
	}//end testUnknownAppReturnsNotFound()

	/**
	 * A valid app with a readable manifest.json is enriched and returned.
	 *
	 * Uses a real temporary file to simulate the manifest on disk.
	 *
	 * @return void
	 */
	public function testValidAppReturnsEnrichedManifest(): void {
		// Write a temp manifest.json.
		$tmpDir = sys_get_temp_dir() . '/manifest_ctrl_test_' . uniqid('', true);
		mkdir($tmpDir . '/src', 0755, true);
		file_put_contents(
			$tmpDir . '/src/manifest.json',
			json_encode(['version' => '1.0.0', 'currentUserSchema' => 'user-profile'])
		);

		$this->appManager
			->method('getAppPath')
			->willReturn($tmpDir);

		$enriched = [
			'version' => '1.0.0',
			'currentUserSchema' => 'user-profile',
			'runtime' => ['user' => ['id' => 'alice']],
		];

		$this->manifestService
			->expects($this->once())
			->method('getEnrichedManifest')
			->willReturn($enriched);

		$result = $this->controller->index('myapp');

		$this->assertSame(expected: Http::STATUS_OK, actual: $result->getStatus());
		$data = $result->getData();
		$this->assertSame(expected: 'alice', actual: $data['runtime']['user']['id']);

		// Conditional-request contract: the ETag is the md5 of the payload
		// (NotModifiedMiddleware serves 304 on If-None-Match), and the default
		// `no-store` is replaced with `private, no-cache` so the browser may
		// store-and-revalidate.
		$this->assertSame(expected: md5(json_encode($enriched)), actual: $result->getETag());
		$headers = $result->getHeaders();
		$this->assertSame(expected: 'private, no-cache', actual: $headers['Cache-Control']);

		// Clean up.
		unlink($tmpDir . '/src/manifest.json');
		rmdir($tmpDir . '/src');
		rmdir($tmpDir);
	}//end testValidAppReturnsEnrichedManifest()

	/**
	 * When ManifestService throws, the controller returns 500.
	 *
	 * @return void
	 */
	public function testServiceExceptionReturnsInternalServerError(): void {
		$tmpDir = sys_get_temp_dir() . '/manifest_ctrl_err_' . uniqid('', true);
		mkdir($tmpDir . '/src', 0755, true);
		file_put_contents(
			$tmpDir . '/src/manifest.json',
			json_encode(['version' => '1.0.0'])
		);

		$this->appManager
			->method('getAppPath')
			->willReturn($tmpDir);

		$this->manifestService
			->method('getEnrichedManifest')
			->willThrowException(new \RuntimeException('DB offline'));

		$result = $this->controller->index('myapp');

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $result->getStatus());

		// Clean up.
		unlink($tmpDir . '/src/manifest.json');
		rmdir($tmpDir . '/src');
		rmdir($tmpDir);
	}//end testServiceExceptionReturnsInternalServerError()
}//end class
