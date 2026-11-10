<?php

/**
 * AppHost GenericSettingsControllerBase — read/write failure translation tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Controller;

use OCA\OpenRegister\AppHost\Controller\GenericSettingsControllerBase;
use OCA\OpenRegister\AppHost\Exception\FoundationUnavailableException;
use OCA\OpenRegister\AppHost\Service\GenericSettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * index() and update() translate service failures instead of leaking them;
 * the success path is untouched by the error handling.
 */
class GenericSettingsControllerBaseBehaviourTest extends TestCase {
	private function controller(GenericSettingsService $svc, ?LoggerInterface $logger = null, array $params = []): GenericSettingsControllerBase {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new class('leafapp', $request, $svc, ($logger ?? $this->createMock(LoggerInterface::class))) extends GenericSettingsControllerBase {
		};
	}

	public function testIndexFailureBecomesLeakSafe500AndIsLogged(): void {
		$svc = $this->createMock(GenericSettingsService::class);
		$svc->method('getSettings')->willThrowException(new RuntimeException('SQLSTATE secret detail'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('error');

		$response = $this->controller($svc, $logger)->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertStringNotContainsString('SQLSTATE', (string)json_encode($response->getData()));
	}

	public function testIndexFoundationMissingBecomesExplicit503(): void {
		$svc = $this->createMock(GenericSettingsService::class);
		$svc->method('getSettings')->willThrowException(new FoundationUnavailableException(appId: 'leafapp'));

		$response = $this->controller($svc)->index();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('foundation-unavailable', $response->getData()['error']);
	}

	public function testUpdateFailureBecomesLeakSafe500(): void {
		$svc = $this->createMock(GenericSettingsService::class);
		$svc->expects($this->once())->method('updateSettings')->with(['mode' => 'x'])
			->willThrowException(new RuntimeException('disk full at /var/secret'));

		$response = $this->controller($svc, null, ['mode' => 'x'])->update();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertStringNotContainsString('/var/secret', (string)json_encode($response->getData()));
	}

	public function testUpdateFoundationMissingBecomesExplicit503(): void {
		$svc = $this->createMock(GenericSettingsService::class);
		$svc->method('updateSettings')->willThrowException(new FoundationUnavailableException(appId: 'leafapp'));

		$response = $this->controller($svc)->create();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('foundation-unavailable', $response->getData()['error']);
	}

	public function testIndexWithoutIsAdminFlagStripsRegister(): void {
		$svc = $this->createMock(GenericSettingsService::class);
		$svc->method('getSettings')->willReturn(['register' => 'secret-uuid', 'title' => 'x']);

		$response = $this->controller($svc)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['title' => 'x'], $response->getData());
	}
}
