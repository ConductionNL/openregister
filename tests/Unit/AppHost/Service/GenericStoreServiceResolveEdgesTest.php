<?php

/**
 * AppHost GenericStoreService — resolve() when the store is not there to ask.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Service;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\RegistryCall;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCA\OpenRegister\AppHost\Service\StoreRegistryAccess;
use OCA\OpenRegister\AppHost\Service\StoreSearch;
use OCA\OpenRegister\Service\Security\OutboundAddressGuard;
use OCA\OpenRegister\Service\Security\SafeFetchUrl;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An unconfigured store is never contacted, a failing one resolves nothing,
 * and a blank register setting falls back to the descriptor's default.
 */
#[CoversClass(GenericStoreService::class)]
#[UsesClass(StoreDescriptor::class)]
#[UsesClass(StoreRegistryAccess::class)]
#[UsesClass(RegistryCall::class)]
#[UsesClass(OutboundAddressGuard::class)]
#[UsesClass(SafeFetchUrl::class)]
#[UsesClass(StoreSearch::class)]
class GenericStoreServiceResolveEdgesTest extends TestCase {

	/**
	 * The HTTP client factory mock.
	 *
	 * @var IClientService&MockObject
	 */
	private IClientService&MockObject $clientService;

	/**
	 * The app config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->clientService = $this->createMock(IClientService::class);
		$this->appConfig     = $this->createMock(IAppConfig::class);
	}//end setUp()

	/**
	 * Store settings for the app.
	 *
	 * @param string $url      The registry URL.
	 * @param string $register The register setting.
	 *
	 * @return void
	 */
	private function configure(string $url, string $register): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'registry_url' => $url,
				'registry_register' => $register,
				default => $default,
			}
		);
	}//end configure()

	/**
	 * A client answering every GET with the status and body, recording the URL.
	 *
	 * @param int         $status The status.
	 * @param string      $body   The body.
	 * @param string|null $url    The URL asked for.
	 *
	 * @return void
	 */
	private function answer(int $status, string $body, ?string &$url = null): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			static function (string $asked) use ($response, &$url): IResponse {
				$url = $asked;
				return $response;
			}
		);
		$this->clientService->method('newClient')->willReturn($client);
	}//end answer()

	/**
	 * The service under test.
	 *
	 * @return GenericStoreService
	 */
	private function service(): GenericStoreService {
		return new GenericStoreService(
			registry: new StoreRegistryAccess($this->clientService, $this->appConfig),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	public function testAnUnconfiguredStoreResolvesNothingWithoutARequest(): void {
		$this->configure(url: '', register: 'openbuild');
		$this->clientService->expects($this->never())->method('newClient');

		$this->assertNull($this->service()->resolve(new StoreDescriptor('openbuild', 'application-template', 'openbuild'), 'crm'));
	}//end testAnUnconfiguredStoreResolvesNothingWithoutARequest()

	public function testAFailingStoreResolvesNothing(): void {
		$this->configure(url: 'https://93.184.216.34/', register: 'openbuild');
		$this->answer(503, '{"results":[{"slug":"crm"}]}');

		$this->assertNull($this->service()->resolve(new StoreDescriptor('openbuild', 'application-template', 'openbuild'), 'crm'));
	}//end testAFailingStoreResolvesNothing()

	public function testABlankRegisterSettingFallsBackToTheDefaultRegister(): void {
		$this->configure(url: 'https://93.184.216.34/', register: '   ');
		$this->answer(200, '{"results":[]}', $url);

		$this->service()->search(descriptor: new StoreDescriptor('openbuild', 'application-template', 'fallback-register'));

		$this->assertStringContainsString('/fallback-register/application-template', (string)$url);
	}//end testABlankRegisterSettingFallsBackToTheDefaultRegister()
}//end class
