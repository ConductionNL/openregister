<?php

/**
 * AppHost GenericSettingsControllerBase — envelope, auth-posture and
 * fail-mode translation tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Controller\GenericSettingsControllerBase;
use OCA\OpenRegister\AppHost\Exception\ConfigurationMissingException;
use OCA\OpenRegister\AppHost\Exception\FoundationUnavailableException;
use OCA\OpenRegister\AppHost\Service\GenericSettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The canonical index/update/load surface: ADR-050 envelope, ADR-049 explicit
 * 503 on foundation-missing, leak-safe 500 on unexpected throwables.
 */
class GenericSettingsControllerBaseTest extends TestCase {
	/**
	 * Builds a concrete subclass instance (the base is abstract, as a leaf
	 * app's SettingsController would subclass it).
	 */
	private function controller(GenericSettingsService $svc, ?IRequest $request = null): GenericSettingsControllerBase {
		return new class('myapp', ($request ?? $this->createMock(IRequest::class)), $svc, $this->createMock(LoggerInterface::class)) extends GenericSettingsControllerBase {
		};
	}//end controller()

	public function testIndexStripsRegisterForNonAdmin(): void {
		$svc = $this->createMock(GenericSettingsService::class);
		$svc->method('getSettings')->willReturn(['register' => 'secret-uuid', 'isAdmin' => false, 'openregisters' => true]);

		$data = $this->controller($svc)->index()->getData();
		$this->assertArrayNotHasKey('register', $data, 'register UUID must not leak to non-admins');
	}//end testIndexStripsRegisterForNonAdmin()

	public function testIndexKeepsRegisterForAdmin(): void {
		$svc = $this->createMock(GenericSettingsService::class);
		$svc->method('getSettings')->willReturn(['register' => 'secret-uuid', 'isAdmin' => true, 'openregisters' => true]);

		$data = $this->controller($svc)->index()->getData();
		$this->assertSame('secret-uuid', $data['register']);
	}//end testIndexKeepsRegisterForAdmin()

	public function testUpdateReturnsFlatRefreshedSettings(): void {
		// ADR-050: flat purpose-shaped payload, no {message, data} wrapper.
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['register' => 'new-uuid']);

		$svc = $this->createMock(GenericSettingsService::class);
		$svc->expects($this->once())
			->method('updateSettings')
			->with(['register' => 'new-uuid'])
			->willReturn(['register' => 'new-uuid', 'isAdmin' => true, 'openregisters' => true]);

		$response = $this->controller($svc, $request)->update();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('new-uuid', $response->getData()['register']);
		$this->assertArrayNotHasKey('data', $response->getData());
	}//end testUpdateReturnsFlatRefreshedSettings()

	public function testCreateAliasesUpdateForLegacyRouteCompatibility(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$svc = $this->createMock(GenericSettingsService::class);
		$svc->expects($this->once())->method('updateSettings')->willReturn(['isAdmin' => true]);

		$this->assertSame(Http::STATUS_OK, $this->controller($svc, $request)->create()->getStatus());
	}//end testCreateAliasesUpdateForLegacyRouteCompatibility()

	public function testLoadPassesForceFlagThrough(): void {
		$svc = $this->createMock(GenericSettingsService::class);
		$svc->expects($this->once())
			->method('loadConfiguration')
			->with(false)
			->willReturn(['success' => true, 'message' => 'ok', 'version' => '1.0.0']);

		$response = $this->controller($svc)->load(force: false);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}//end testLoadPassesForceFlagThrough()

	public function testLoadTranslatesFoundationUnavailableTo503(): void {
		// Scenario: Foundation missing is explicit — 503 + machine-readable reason.
		$svc = $this->createMock(GenericSettingsService::class);
		$svc->method('loadConfiguration')->willThrowException(new FoundationUnavailableException(appId: 'myapp'));

		$response = $this->controller($svc)->load();
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('foundation-unavailable', $data['error']);
		$this->assertStringContainsString('OpenRegister', $data['message']);
	}//end testLoadTranslatesFoundationUnavailableTo503()

	public function testLoadTranslatesConfigurationMissingTo503(): void {
		$svc = $this->createMock(GenericSettingsService::class);
		$svc->method('loadConfiguration')->willThrowException(
			new ConfigurationMissingException(appId: 'myapp', configKey: 'lib/Settings/myapp_register.json')
		);

		$response = $this->controller($svc)->load();
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('configuration-missing', $response->getData()['error']);
	}//end testLoadTranslatesConfigurationMissingTo503()

	public function testUnexpectedThrowableBecomesGenericLeakSafe500(): void {
		// Scenario: Internal detail is not leaked.
		$svc = $this->createMock(GenericSettingsService::class);
		$svc->method('loadConfiguration')->willThrowException(new \RuntimeException('SQLSTATE[42S02] secret table detail'));

		$response = $this->controller($svc)->load();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertStringNotContainsString('SQLSTATE', json_encode($response->getData()));
	}//end testUnexpectedThrowableBecomesGenericLeakSafe500()

	public function testIndexCarriesNoAdminRequiredAttribute(): void {
		$rm = new ReflectionMethod(GenericSettingsControllerBase::class, 'index');
		$attrs = array_map(static fn ($a) => $a->getName(), $rm->getAttributes());
		$this->assertContains('OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired', $attrs);
	}//end testIndexCarriesNoAdminRequiredAttribute()

	public function testMutatingMethodsCarryAuthorizedAdminSettingOnly(): void {
		// update()/load()/create() are admin-or-delegated (fail-closed): the
		// AuthorizedAdminSetting attribute without NoAdminRequired.
		foreach (['update', 'load', 'create'] as $method) {
			$rm = new ReflectionMethod(GenericSettingsControllerBase::class, $method);
			$attrs = array_map(static fn ($a) => $a->getName(), $rm->getAttributes());
			$this->assertContains(
				'OCP\\AppFramework\\Http\\Attribute\\AuthorizedAdminSetting',
				$attrs,
				"$method() must carry AuthorizedAdminSetting"
			);
			$this->assertNotContains(
				'OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired',
				$attrs,
				"$method() must not relax to any authenticated user"
			);
		}
	}//end testMutatingMethodsCarryAuthorizedAdminSettingOnly()
}//end class
