<?php

/**
 * Characterisation of the router's connector dispatch: the `request`
 * fallback, a CallService with neither method, the error log line of an
 * unclassified upstream failure, and a container that hands back no
 * ObjectService.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-registry/spec.md#requirement-external-strategy-providers-must-route-through-openconnector
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Classes.ClassFileName.NoMatch -- small stand-ins live beside the test.
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses -- small stand-ins live beside the test.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Integration\UpstreamRequest;
use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * An external provider routed through source `wiki`.
 */
class DispatchGoldenProvider extends AbstractIntegrationProvider {
	public function getId(): string {
		return 'wiki';
	}//end getId()

	public function getLabel(): string {
		return 'Wiki';
	}//end getLabel()

	public function getIcon(): string {
		return 'FileDocument';
	}//end getIcon()

	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	public function getStorageStrategy(): string {
		return 'external';
	}//end getStorageStrategy()

	public function getOpenConnectorSource(): ?string {
		return 'wiki';
	}//end getOpenConnectorSource()

	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		return [];
	}//end list()
}//end class

/**
 * An older connector CallService that only exposes `request()`.
 */
class DispatchGoldenRequestOnlyService {
	/**
	 * What `request()` was handed.
	 *
	 * @var array<int, mixed>
	 */
	public array $seen = [];

	public function request($source, string $method, string $path, array $options) {
		$this->seen = [$method, $path, $options];

		return ['statusCode' => 200, 'response' => ['body' => '{"ok":true}']];
	}//end request()
}//end class

/**
 * The router's dispatch edges, pinned.
 */
class ExternalIntegrationRouterDispatchGoldenTest extends TestCase {

	/**
	 * Error lines the router logged.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * A router whose container answers the given ObjectService and CallService.
	 *
	 * @param mixed       $objectService What the container returns for the object contract.
	 * @param object|null $callService   What the container returns for the CallService.
	 *
	 * @return ExternalIntegrationRouter The router.
	 */
	private function router(mixed $objectService, ?object $callService): ExternalIntegrationRouter {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('isEnabledForUser')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $callService) {
				if ($id === ObjectOperationsInterface::class) {
					return $objectService;
				}

				return $callService;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		return new ExternalIntegrationRouter($appManager, $container, $logger);
	}//end router()

	/**
	 * An ObjectService that finds source `wiki` in every register.
	 *
	 * @return ObjectService The double.
	 */
	private function objects(): ObjectService {
		$source = new ObjectEntity();
		$source->setObject(['slug' => 'wiki', 'location' => 'https://wiki.example']);

		$objects = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$objects->method('find')->willReturn($source);

		return $objects;
	}//end objects()

	public function testARequestOnlyConnectorIsCalledThroughRequest(): void {
		$service = new DispatchGoldenRequestOnlyService();

		$this->router($this->objects(), $service)->call(new DispatchGoldenProvider(), new UpstreamRequest(method: 'GET', path: 'pages', options: ['query' => ['q' => 'x']]));

		$this->assertSame('GET', $service->seen[0]);
		$this->assertSame('/pages', $service->seen[1]);
		$this->assertSame([], $this->errors);
	}//end testARequestOnlyConnectorIsCalledThroughRequest()

	public function testARequestOnlyConnectorCannotWithholdBodies(): void {
		$service = new DispatchGoldenRequestOnlyService();

		try {
			$this->router($this->objects(), $service)->call(new DispatchGoldenProvider(), new UpstreamRequest(method: 'GET', path: 'pages', options: ['withholdBodies' => true]));
			$this->fail('A connector that cannot withhold bodies must not be called.');
		} catch (ProviderUnavailableException $e) {
			$this->assertSame(ProviderUnavailableException::CAUSE_CONNECTOR_CANNOT_WITHHOLD_BODIES, $e->getCause());
		}

		$this->assertSame([], $service->seen);
	}//end testARequestOnlyConnectorCannotWithholdBodies()

	public function testAConnectorWithNeitherMethodIsAnUpstreamFailure(): void {
		try {
			$this->router($this->objects(), new stdClass())->call(new DispatchGoldenProvider(), new UpstreamRequest(method: 'POST', path: 'pages', options: []));
			$this->fail('A connector without call/request must fail.');
		} catch (ProviderUnavailableException $e) {
			$this->assertSame(ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN, $e->getCause());
			$this->assertSame('Upstream service for integration "wiki" is unreachable.', $e->getMessage());
			$this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
			$this->assertSame('The connector CallService does not expose a known call/request method.', $e->getPrevious()->getMessage());
		}

		$this->assertSame(['[ExternalIntegrationRouter] upstream call failed for provider wiki POST pages'], $this->errors);
	}//end testAConnectorWithNeitherMethodIsAnUpstreamFailure()

	public function testTheMetaCallNamesItselfInTheFailureLine(): void {
		try {
			$this->router($this->objects(), new stdClass())->callWithMeta(new DispatchGoldenProvider(), new UpstreamRequest(method: 'GET', path: 'pages/1', options: []));
			$this->fail('A connector without call/request must fail.');
		} catch (ProviderUnavailableException $e) {
			$this->assertSame(ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN, $e->getCause());
		}

		$this->assertSame(['[ExternalIntegrationRouter] upstream call (with meta) failed for provider wiki GET pages/1'], $this->errors);
	}//end testTheMetaCallNamesItselfInTheFailureLine()

	public function testAContainerWithoutAnObjectServiceIsASourceMissing(): void {
		try {
			$this->router(new stdClass(), new DispatchGoldenRequestOnlyService())->call(new DispatchGoldenProvider(), new UpstreamRequest(method: 'GET', path: 'pages', options: []));
			$this->fail('No ObjectService means no source.');
		} catch (ProviderUnavailableException $e) {
			$this->assertSame(ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING, $e->getCause());
			$this->assertSame('OpenConnector source "wiki" for integration "wiki" is missing or unreadable.', $e->getMessage());
			$this->assertSame('The container did not return an ObjectService.', $e->getPrevious()->getMessage());
		}
	}//end testAContainerWithoutAnObjectServiceIsASourceMissing()
}//end class
