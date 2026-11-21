<?php

/**
 * AppHost GenericSettingsController — write and import path tests.
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

use OCA\OpenRegister\AppHost\Controller\GenericSettingsController;
use OCA\OpenRegister\AppHost\Service\AppHostSettingsService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * create()/update() pass the request params to the service and wrap the
 * result; load() always forces a fresh import; index() without an isAdmin flag
 * treats the caller as non-admin.
 */
class GenericSettingsControllerBehaviourTest extends TestCase {
	private function controller(AppHostSettingsService $svc, array $params = []): GenericSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new GenericSettingsController('leafapp', $request, $svc);
	}

	public function testCreatePassesRequestParamsAndWrapsConfig(): void {
		$svc = $this->createMock(AppHostSettingsService::class);
		$svc->expects($this->once())->method('updateSettings')
			->with(['register' => 'r-1', 'mode' => 'strict'])
			->willReturn(['register' => 'r-1', 'mode' => 'strict', 'saved' => true]);

		$response = $this->controller($svc, ['register' => 'r-1', 'mode' => 'strict'])->create();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['success' => true, 'config' => ['register' => 'r-1', 'mode' => 'strict', 'saved' => true]],
			$response->getData()
		);
	}

	public function testUpdateUsesTheSameWritePathAsCreate(): void {
		$svc = $this->createMock(AppHostSettingsService::class);
		$svc->expects($this->once())->method('updateSettings')->with(['mode' => 'loose'])->willReturn(['mode' => 'loose']);

		$response = $this->controller($svc, ['mode' => 'loose'])->update();

		$this->assertSame(['success' => true, 'config' => ['mode' => 'loose']], $response->getData());
	}

	public function testLoadForcesFreshImportAndReturnsResult(): void {
		$svc = $this->createMock(AppHostSettingsService::class);
		$svc->expects($this->once())->method('loadConfiguration')->with(true)
			->willReturn(['success' => true, 'version' => '2.0.0']);
		$svc->expects($this->never())->method('updateSettings');

		$response = $this->controller($svc)->load();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true, 'version' => '2.0.0'], $response->getData());
	}

	public function testIndexWithoutIsAdminFlagStripsRegister(): void {
		$svc = $this->createMock(AppHostSettingsService::class);
		$svc->method('getSettings')->willReturn(['register' => 'secret-uuid', 'title' => 'x']);

		$this->assertSame(['title' => 'x'], $this->controller($svc)->index()->getData());
	}
}
