<?php

/**
 * ApiSurfaceControllerTest — the contract test for the three published reads
 * (gate 25).
 *
 * The session split is the one to get wrong quietly: an anonymous caller that
 * receives the operational switches has been told the security posture of a
 * gemeente's installation, and nothing in the response would look unusual.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ApiSurfaceController;
use OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService;
use OCA\OpenRegister\Service\ApiVersion\ApiContractService;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Controller\ApiSurfaceController
 */
class ApiSurfaceControllerTest extends TestCase {

	/**
	 * Build the controller.
	 *
	 * @param bool $loggedIn Whether a session is present.
	 * @param string $declaration The raw api_versions value.
	 * @param ApiContractService|null $contracts Override the contract service.
	 *
	 * @return ApiSurfaceController The controller.
	 */
	private function controller(
		bool $loggedIn = false,
		string $declaration = '',
		?ApiContractService $contracts = null,
	): ApiSurfaceController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($declaration);
		$appConfig->method('getValueBool')->willReturn(true);

		$catalogue = new ApiVersionCatalogue($appConfig, $this->createMock(LoggerInterface::class));

		$capabilities = $this->createMock(ApiCapabilitiesService::class);
		$capabilities->method('publicCapabilities')->willReturn(['currentVersion' => '1', 'limits' => []]);
		$capabilities->method('sessionCapabilities')->willReturn(['currentVersion' => '1', 'limits' => [], 'features' => []]);

		$session = $this->createMock(IUserSession::class);
		$session->method('isLoggedIn')->willReturn($loggedIn);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn('');

		return new ApiSurfaceController(
			'openregister',
			$request,
			$capabilities,
			$catalogue,
			($contracts ?? $this->createMock(ApiContractService::class)),
			$session,
			$this->createMock(LoggerInterface::class),
		);
	}//end controller()

	public function testAnAnonymousCallerGetsTheLimitsAndNoSwitches(): void {
		$response = $this->controller(loggedIn: false)->capabilities();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayHasKey('limits', $response->getData());
		$this->assertArrayNotHasKey(
			'features',
			$response->getData(),
			'An anonymous caller reading the operational posture would look exactly like a normal response.'
		);
	}//end testAnAnonymousCallerGetsTheLimitsAndNoSwitches()

	public function testACallerWithASessionAlsoGetsTheSwitches(): void {
		$response = $this->controller(loggedIn: true)->capabilities();

		$this->assertArrayHasKey('features', $response->getData());
	}//end testACallerWithASessionAlsoGetsTheSwitches()

	public function testTheVersionListNamesEveryDeclaredVersionIncludingWithdrawnOnes(): void {
		$response = $this->controller(
			declaration: json_encode(
				[
					['id' => '1', 'status' => 'withdrawn', 'successor' => '2'],
					['id' => '2', 'status' => 'supported'],
				]
			)
		)->versions();

		$this->assertSame('2', $response->getData()['currentVersion']);
		$this->assertSame(
			['1', '2'],
			array_column($response->getData()['versions'], 'version'),
			'A consumer still calling a withdrawn version must find it named, not missing.'
		);
	}//end testTheVersionListNamesEveryDeclaredVersionIncludingWithdrawnOnes()

	public function testADescriptionIsServedForAServedVersion(): void {
		$contracts = $this->createMock(ApiContractService::class);
		$contracts->method('documentFor')->willReturn(['openapi' => '3.1.0', 'paths' => []]);

		$response = $this->controller(contracts: $contracts)->contract(version: '1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('3.1.0', $response->getData()['openapi']);
	}//end testADescriptionIsServedForAServedVersion()

	public function testAnUndeclaredVersionHasNoDescription(): void {
		$response = $this->controller()->contract(version: '9');

		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$response->getStatus(),
			'This path names a document; a document that does not exist is a missing resource.'
		);
		$this->assertSame(['1'], $response->getData()['servedVersions']);
	}//end testAnUndeclaredVersionHasNoDescription()

	public function testAWithdrawnVersionHasNoDescriptionAtAll(): void {
		$response = $this->controller(
			declaration: json_encode(
				[
					['id' => '1', 'status' => 'withdrawn', 'successor' => '2'],
					['id' => '2', 'status' => 'supported'],
				]
			)
		)->contract(version: '1');

		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
		$this->assertSame(
			'2',
			$response->getData()['successorVersion'],
			'Publishing it would let an integrator generate a client and meet the withdrawal at runtime.'
		);
	}//end testAWithdrawnVersionHasNoDescriptionAtAll()

	public function testAFailedGenerationIsAnErrorRatherThanAnEmptyDocument(): void {
		$contracts = $this->createMock(ApiContractService::class);
		$contracts->method('documentFor')->willThrowException(new RuntimeException('no database'));

		$response = $this->controller(contracts: $contracts)->contract(version: '1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayNotHasKey(
			'paths',
			$response->getData(),
			'An empty paths map would read as an API with no endpoints.'
		);
	}//end testAFailedGenerationIsAnErrorRatherThanAnEmptyDocument()
}//end class
