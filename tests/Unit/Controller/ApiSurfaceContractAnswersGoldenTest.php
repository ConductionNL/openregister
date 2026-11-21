<?php

/**
 * Golden test pinning the exact answers of ApiSurfaceController::contract:
 * undeclared, withdrawn, failed generation (with its log) and served.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ApiSurfaceController;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService;
use OCA\OpenRegister\Service\ApiVersion\ApiContractService;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue;
use OCA\OpenRegister\Service\ApiVersion\ApiVersion;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ApiSurfaceControllerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ApiSurfaceContractAnswersGoldenTest extends TestCase {

	private array $generated = [];

	private array $errors = [];

	private function controller(string $register, bool $generationFails): ApiSurfaceController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn(
			(string)json_encode(
				[
					['id' => '1', 'status' => 'withdrawn', 'successor' => '2'],
					['id' => '2', 'status' => 'supported'],
				]
			)
		);
		$appConfig->method('getValueBool')->willReturn(true);

		$contracts = $this->createMock(ApiContractService::class);
		$contracts->method('documentFor')->willReturnCallback(
			function (ApiVersion $version, ?string $registerId) use ($generationFails): array {
				$this->generated[] = [$version->id, $registerId];
				if ($generationFails === true) {
					throw new RuntimeException('no database');
				}

				return ['openapi' => '3.1.0', 'paths' => []];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context['exception']->getMessage()];
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => ($name === 'register' ? $register : $default)
		);

		return ApiSurfaceControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(ApiCapabilitiesService::class),
			new ApiVersionCatalogue($appConfig, $this->createMock(LoggerInterface::class)),
			$contracts,
			new ControllerSession($this->createMock(IUserSession::class), $this->createMock(IGroupManager::class), $logger),
		);
	}

	public function testAnUndeclaredVersionNamesTheServedOnes(): void {
		$response = $this->controller('', false)->contract(version: '9');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(
			['error' => 'No API version "9" is declared on this instance.', 'servedVersions' => ['2']],
			$response->getData()
		);
		$this->assertSame([], $this->generated);
	}

	public function testAWithdrawnVersionNamesItsSuccessor(): void {
		$response = $this->controller('', false)->contract(version: '1');

		$this->assertSame(410, $response->getStatus());
		$this->assertSame(
			[
				'error' => 'API version 1 has been withdrawn. Use version 2.',
				'successorVersion' => '2',
				'servedVersions' => ['2'],
			],
			$response->getData()
		);
		$this->assertSame([], $this->generated);
	}

	public function testAServedVersionIsGeneratedForTheTrimmedRegister(): void {
		$response = $this->controller(' 5 ', false)->contract(version: '2');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['openapi' => '3.1.0', 'paths' => []], $response->getData());
		$this->assertSame([['2', '5']], $this->generated);

		$this->controller('  ', false)->contract(version: '2');
		$this->assertSame([['2', '5'], ['2', null]], $this->generated);
	}

	public function testAFailedGenerationIsLoggedAndAnswers500(): void {
		$response = $this->controller('', true)->contract(version: '2');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'The description for API version 2 could not be generated.'], $response->getData());
		$this->assertSame([['OpenRegister: could not generate the description for API version 2.', 'no database']], $this->errors);
	}
}//end class
