<?php

/**
 * Unit tests for ExternalIntegrationRouter.
 *
 * Covers:
 *  - rejects non-external providers (LogicException)
 *  - rejects external providers without OpenConnector source
 *  - throws CAUSE_OPENCONNECTOR_DOWN when openconnector app missing
 *  - probe() reports the right descriptor in each failure mode
 *  - finds the declared source through ObjectService, in the connector's
 *    register under either slug, and classifies a miss as source-missing
 *  - reads the connector's call log in both shapes: integriq's ObjectEntity
 *    and the older getResponse() CallLog
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- test method names + arrange/act/assert structure make intent obvious; matches BrpPersoonProviderTest convention.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion + local fixture helpers take positional args by convention; mirroring BrpPersoonProviderTest in this repo.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * External provider stub.
 */
class FakeExternalProvider extends AbstractIntegrationProvider {
	public function __construct(
		private string $id = 'xwiki',
		private ?string $source = 'xwiki',
		private string $storage = 'external',
	) {
	}//end __construct()

	public function getId(): string {
		return $this->id;
	}//end getId()

	public function getLabel(): string {
		return 'XWiki';
	}//end getLabel()

	public function getIcon(): string {
		return 'FileDocumentMultiple';
	}//end getIcon()

	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	public function getStorageStrategy(): string {
		return $this->storage;
	}//end getStorageStrategy()

	public function getOpenConnectorSource(): ?string {
		return $this->source;
	}//end getOpenConnectorSource()

	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		return [];
	}//end list()
}//end class

/**
 * Local provider stub.
 */
class FakeLocalProvider extends AbstractIntegrationProvider {
	public function getId(): string {
		return 'files';
	}//end getId()

	public function getLabel(): string {
		return 'Files';
	}//end getLabel()

	public function getIcon(): string {
		return 'Paperclip';
	}//end getIcon()

	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	public function getStorageStrategy(): string {
		return 'magic-column';
	}//end getStorageStrategy()

	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		return [];
	}//end list()
}//end class

/**
 * Stand-in for an older connector CallLog exposing getStatusCode() and
 * getResponse(). integriq's own call log is an ObjectEntity, built with
 * {@see ExternalIntegrationRouterTest::integriqCallLog()}.
 */
class FakeCallLog {
	public function __construct(
		private int $status,
		private ?array $response,
	) {
	}//end __construct()

	public function getStatusCode(): int {
		return $this->status;
	}//end getStatusCode()

	public function getResponse(): ?array {
		return $this->response;
	}//end getResponse()
}//end class

/**
 * Stand-in for the connector's CallService: returns a preset call log and
 * remembers the source it was handed.
 */
class FakeCallService {
	public mixed $receivedSource = null;

	public function __construct(
		private object $log,
	) {
	}//end __construct()

	public function call($source, string $endpoint = '', string $method = 'GET', array $config = []) {
		$this->receivedSource = $source;
		return $this->log;
	}//end call()
}//end class

/**
 * A CallService that fails loudly if ever called — proves mock mode never
 * touches the real upstream transport.
 */
class ExplodingCallService {
	public function call($source, string $endpoint = '', string $method = 'GET', array $config = []) {
		throw new \RuntimeException('CallService::call must NOT be reached in mock mode');
	}//end call()
}//end class

/**
 * Unit tests for ExternalIntegrationRouter.
 */
class ExternalIntegrationRouterTest extends TestCase {
	/**
	 * Every find() the ObjectService double received, as named arguments.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $finds = [];

	private function buildRouter(bool $openConnectorInstalled): ExternalIntegrationRouter {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')
			->with('openconnector')
			->willReturn($openConnectorInstalled);
		$appManager->method('isEnabledForUser')
			->with('openconnector')
			->willReturn($openConnectorInstalled);

		$container = $this->createMock(ContainerInterface::class);

		return new ExternalIntegrationRouter($appManager, $container, new NullLogger());
	}//end buildRouter()

	/**
	 * A source object the way integriq stores it.
	 *
	 * @param array<string,mixed> $data The source payload.
	 *
	 * @return ObjectEntity
	 */
	private function sourceEntity(array $data): ObjectEntity {
		$source = new ObjectEntity();
		$source->setObject($data);

		return $source;
	}//end sourceEntity()

	/**
	 * A call log the way integriq's CallService returns it: an ObjectEntity
	 * whose object carries `response`, or only a top-level `statusCode` for an
	 * early exit.
	 *
	 * @param array<string,mixed> $data The call log payload.
	 *
	 * @return ObjectEntity
	 */
	private function integriqCallLog(array $data): ObjectEntity {
		$log = new ObjectEntity();
		$log->setObject($data);

		return $log;
	}//end integriqCallLog()

	/**
	 * An ObjectService double whose find() answers from a `register/id` map
	 * and throws DoesNotExistException for anything else, like the real one.
	 *
	 * @param array<string,ObjectEntity> $sources Sources keyed `register/id`.
	 * @param \Throwable|null $failure Thrown by every find() instead, when given.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $sources, ?\Throwable $failure = null): ObjectService {
		$objectService = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();

		$objectService->method('find')->willReturnCallback(
			function (
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				$register = null,
				$schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $_render = true,
				bool $_audit = true,
			) use ($sources, $failure): ?ObjectEntity {
				$this->finds[] = [
					'id' => $id,
					'register' => $register,
					'schema' => $schema,
					'_rbac' => $_rbac,
					'_multitenancy' => $_multitenancy,
					'_audit' => $_audit,
				];

				if ($failure !== null) {
					throw $failure;
				}

				$key = $register . '/' . $id;
				if (isset($sources[$key]) === false) {
					throw new DoesNotExistException('Object not found');
				}

				return $sources[$key];
			}
		);

		return $objectService;
	}//end objectService()

	/**
	 * Build a router on an instance where the connector is installed, reading
	 * sources from the given ObjectService and calling through the given
	 * CallService.
	 *
	 * @param ObjectService $objectService Answers the source lookup.
	 * @param object|null $callService The connector CallService stand-in.
	 *
	 * @return ExternalIntegrationRouter
	 */
	private function routerOn(ObjectService $objectService, ?object $callService = null): ExternalIntegrationRouter {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('isEnabledForUser')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $callService) {
				if ($id === ObjectService::class) {
					return $objectService;
				}

				if (str_ends_with($id, 'CallService') === true) {
					return $callService;
				}

				return null;
			}
		);

		return new ExternalIntegrationRouter($appManager, $container, new NullLogger());
	}//end routerOn()

	/**
	 * Build a router whose source `xwiki` exists and whose CallService returns
	 * the given call log.
	 *
	 * @param object $log The call log the fake CallService returns.
	 *
	 * @return ExternalIntegrationRouter
	 */
	private function buildRouterWithCallLog(object $log): ExternalIntegrationRouter {
		$sources = [
			'integriq/xwiki' => $this->sourceEntity(['slug' => 'xwiki', 'location' => 'https://wiki.example']),
			'integriq/kvk' => $this->sourceEntity(['slug' => 'kvk', 'location' => 'https://kvk.example']),
		];

		return $this->routerOn($this->objectService($sources), new FakeCallService($log));
	}//end buildRouterWithCallLog()

	public function testCallRejectsNonExternalProvider(): void {
		$router = $this->buildRouter(true);
		$provider = new FakeLocalProvider();

		$this->expectException(\LogicException::class);
		$router->call($provider, 'GET', '/some/path');
	}//end testCallRejectsNonExternalProvider()

	public function testCallRejectsExternalProviderWithoutSource(): void {
		$router = $this->buildRouter(true);
		$provider = new FakeExternalProvider(source: null);

		try {
			$router->call($provider, 'GET', '/some/path');
			$this->fail('Expected ProviderUnavailableException');
		} catch (ProviderUnavailableException $e) {
			$this->assertSame(
				ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING,
				$e->getCause()
			);
			$this->assertSame(
				['cause' => ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING],
				$e->getDetails()
			);
		}
	}//end testCallRejectsExternalProviderWithoutSource()

	public function testCallReportsOpenConnectorDownWhenAppMissing(): void {
		$router = $this->buildRouter(false);
		$provider = new FakeExternalProvider();

		try {
			$router->call($provider, 'GET', '/some/path');
			$this->fail('Expected ProviderUnavailableException');
		} catch (ProviderUnavailableException $e) {
			$this->assertSame(
				ProviderUnavailableException::CAUSE_OPENCONNECTOR_DOWN,
				$e->getCause()
			);
		}
	}//end testCallReportsOpenConnectorDownWhenAppMissing()

	public function testProbeReturnsOkForLocalProvider(): void {
		$router = $this->buildRouter(false);
		$provider = new FakeLocalProvider();

		$report = $router->probe($provider);
		$this->assertSame('ok', $report['status']);
		$this->assertSame('configured', $report['authStatus']);
	}//end testProbeReturnsOkForLocalProvider()

	public function testProbeReportsUnavailableWhenOpenConnectorMissing(): void {
		$router = $this->buildRouter(false);
		$provider = new FakeExternalProvider();

		$report = $router->probe($provider);
		$this->assertSame('unavailable', $report['status']);
		$this->assertSame('missing', $report['authStatus']);
	}//end testProbeReportsUnavailableWhenOpenConnectorMissing()

	public function testProbeFindsASourceDeclaredByUuid(): void {
		// The provider declares the source uuid. The router must read it from
		// the connector's register and schema as a system read.
		$uuid = '6f1d3c2a-8b7e-4d5f-9a0b-1c2d3e4f5a6b';
		$router = $this->routerOn(
			$this->objectService(['integriq/' . $uuid => $this->sourceEntity(['uuid' => $uuid, 'location' => 'https://x.example'])])
		);

		$report = $router->probe(new FakeExternalProvider(source: $uuid));

		$this->assertSame('ok', $report['status']);
		$this->assertSame('configured', $report['authStatus']);
		$this->assertSame(
			[
				'id' => $uuid,
				'register' => 'integriq',
				'schema' => 'source',
				'_rbac' => false,
				'_multitenancy' => false,
				'_audit' => false,
			],
			$this->finds[0]
		);
	}//end testProbeFindsASourceDeclaredByUuid()

	public function testProbeFindsASourceDeclaredBySlug(): void {
		$router = $this->routerOn($this->objectService(['integriq/kvk' => $this->sourceEntity(['slug' => 'kvk'])]));

		$report = $router->probe(new FakeExternalProvider(source: 'kvk'));

		$this->assertSame('ok', $report['status']);
		$this->assertSame('kvk', $this->finds[0]['id']);
	}//end testProbeFindsASourceDeclaredBySlug()

	public function testSourceUnderTheUnmigratedRegisterSlugIsFound(): void {
		// An instance that has not run integriq's MigrateRegisterSlug still keeps
		// its sources in the `openconnector` register.
		$router = $this->routerOn($this->objectService(['openconnector/kvk' => $this->sourceEntity(['slug' => 'kvk'])]));

		$report = $router->probe(new FakeExternalProvider(source: 'kvk'));

		$this->assertSame('ok', $report['status']);
		$this->assertSame(['integriq', 'openconnector'], array_column($this->finds, 'register'));
	}//end testSourceUnderTheUnmigratedRegisterSlugIsFound()

	public function testMissingSourceRaisesSourceMissing(): void {
		$router = $this->routerOn($this->objectService([]), new ExplodingCallService());

		try {
			$router->call(new FakeExternalProvider(source: 'kvk'), 'GET', 'zoeken');
			$this->fail('Expected ProviderUnavailableException');
		} catch (ProviderUnavailableException $e) {
			$this->assertSame(ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING, $e->getCause());
		}

		$report = $router->probe(new FakeExternalProvider(source: 'kvk'));
		$this->assertSame('unavailable', $report['status']);
		$this->assertSame('missing', $report['authStatus']);
	}//end testMissingSourceRaisesSourceMissing()

	public function testUnreadableSourceRaisesSourceMissing(): void {
		$failure = new \RuntimeException('database gone');
		$router = $this->routerOn($this->objectService([], $failure), new ExplodingCallService());

		try {
			$router->call(new FakeExternalProvider(source: 'kvk'), 'GET', 'zoeken');
			$this->fail('Expected ProviderUnavailableException');
		} catch (ProviderUnavailableException $e) {
			$this->assertSame(ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING, $e->getCause());
			$this->assertSame($failure, $e->getPrevious());
		}
	}//end testUnreadableSourceRaisesSourceMissing()

	public function testConnectorAbsentRaisesDownWithoutLookingUpTheSource(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);
		$appManager->method('isEnabledForUser')->willReturn(false);

		$objectService = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$objectService->expects($this->never())->method('find');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$router = new ExternalIntegrationRouter($appManager, $container, new NullLogger());

		try {
			$router->call(new FakeExternalProvider(source: 'kvk'), 'GET', 'zoeken');
			$this->fail('Expected ProviderUnavailableException');
		} catch (ProviderUnavailableException $e) {
			$this->assertSame(ProviderUnavailableException::CAUSE_OPENCONNECTOR_DOWN, $e->getCause());
		}
	}//end testConnectorAbsentRaisesDownWithoutLookingUpTheSource()

	public function testCallHandsTheFoundSourceToTheCallService(): void {
		$source = $this->sourceEntity(['slug' => 'kvk', 'location' => 'https://kvk.example']);
		$callService = new FakeCallService(
			$this->integriqCallLog(['statusCode' => 200, 'response' => ['statusCode' => 200, 'body' => '{"resultaten":[]}', 'encoding' => 'UTF-8']])
		);
		$router = $this->routerOn($this->objectService(['integriq/kvk' => $source]), $callService);

		$router->call(new FakeExternalProvider(source: 'kvk'), 'GET', 'zoeken');

		$this->assertSame($source, $callService->receivedSource);
	}//end testCallHandsTheFoundSourceToTheCallService()

	public function testCallUnwrapsTheIntegriqCallLogObject(): void {
		// integriq returns the call log as an ObjectEntity; the upstream body
		// sits under getObject()['response']['body'].
		$log = $this->integriqCallLog(
			[
				'statusCode' => 200,
				'response' => ['statusCode' => 200, 'headers' => [], 'body' => '{"resultaten":[{"kvkNummer":"69599084"}]}', 'encoding' => 'UTF-8'],
			]
		);
		$router = $this->buildRouterWithCallLog($log);

		$result = $router->call(new FakeExternalProvider(source: 'kvk'), 'GET', 'zoeken');

		$this->assertSame(['resultaten' => [['kvkNummer' => '69599084']]], $result);
	}//end testCallUnwrapsTheIntegriqCallLogObject()

	public function testCallWithMetaReadsTheIntegriqCallLogObject(): void {
		$log = $this->integriqCallLog(
			[
				'statusCode' => 200,
				'response' => [
					'statusCode' => 200,
					'responseTime' => 88.6,
					'headers' => ['X-Correlation-ID' => ['cid-from-integriq']],
					'body' => '{"personen":[]}',
					'encoding' => 'UTF-8',
				],
			]
		);
		$router = $this->buildRouterWithCallLog($log);

		$result = $router->callWithMeta(new FakeExternalProvider(), 'POST', 'personen');

		$this->assertSame(['personen' => []], $result['body']);
		$this->assertSame(200, $result['meta']['status']);
		$this->assertSame(89, $result['meta']['durationMs']);
		$this->assertSame('cid-from-integriq', $result['meta']['correlationId']);
	}//end testCallWithMetaReadsTheIntegriqCallLogObject()

	public function testIntegriqCallLogAuthErrorIsProviderAuth(): void {
		$router = $this->buildRouterWithCallLog(
			$this->integriqCallLog(['statusCode' => 403, 'response' => ['statusCode' => 403, 'body' => 'denied']])
		);

		try {
			$router->call(new FakeExternalProvider(), 'GET', '');
			$this->fail('Expected ProviderUnavailableException');
		} catch (ProviderUnavailableException $e) {
			$this->assertSame(ProviderUnavailableException::CAUSE_PROVIDER_AUTH, $e->getCause());
		}
	}//end testIntegriqCallLogAuthErrorIsProviderAuth()

	public function testIntegriqEarlyExitCallLogIsUpstreamDown(): void {
		// A disabled source or an exhausted rate limit returns a call log with a
		// top-level statusCode and no response half.
		$router = $this->buildRouterWithCallLog(
			$this->integriqCallLog(['statusCode' => 429, 'statusMessage' => 'Rate limit exceeded'])
		);

		try {
			$router->call(new FakeExternalProvider(), 'GET', '');
			$this->fail('Expected ProviderUnavailableException');
		} catch (ProviderUnavailableException $e) {
			$this->assertSame(ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN, $e->getCause());
		}
	}//end testIntegriqEarlyExitCallLogIsUpstreamDown()

	public function testCallUnwrapsTheCallLogBody(): void {
		// CallService returns a CallLog; the upstream JSON payload is the
		// `body` string inside getResponse() — the router must hand the
		// caller the decoded body, not the CallLog wrapper.
		$body = '{"pageSummaries":[{"id":"xwiki:Sandbox.Page","name":"Page"}]}';
		$log = new FakeCallLog(200, ['statusCode' => 200, 'headers' => [], 'body' => $body, 'encoding' => 'UTF-8']);
		$router = $this->buildRouterWithCallLog($log);

		$result = $router->call(new FakeExternalProvider(), 'GET', '');

		$this->assertArrayHasKey('pageSummaries', $result);
		$this->assertSame('xwiki:Sandbox.Page', $result['pageSummaries'][0]['id']);
	}//end testCallUnwrapsTheCallLogBody()

	public function testCallDecodesBase64EncodedBody(): void {
		$log = new FakeCallLog(200, ['body' => base64_encode('{"items":[]}'), 'encoding' => 'base64']);
		$router = $this->buildRouterWithCallLog($log);

		$this->assertSame(['items' => []], $router->call(new FakeExternalProvider(), 'GET', ''));
	}//end testCallDecodesBase64EncodedBody()

	public function testCallTreatsAuthErrorAsProviderAuth(): void {
		$router = $this->buildRouterWithCallLog(new FakeCallLog(401, ['body' => 'denied']));

		try {
			$router->call(new FakeExternalProvider(), 'GET', '');
			$this->fail('Expected ProviderUnavailableException');
		} catch (ProviderUnavailableException $e) {
			$this->assertSame(ProviderUnavailableException::CAUSE_PROVIDER_AUTH, $e->getCause());
		}
	}//end testCallTreatsAuthErrorAsProviderAuth()

	public function testCallTreatsServerErrorAsUpstreamDown(): void {
		$router = $this->buildRouterWithCallLog(new FakeCallLog(500, ['body' => 'oops']));

		try {
			$router->call(new FakeExternalProvider(), 'GET', '');
			$this->fail('Expected ProviderUnavailableException');
		} catch (ProviderUnavailableException $e) {
			$this->assertSame(ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN, $e->getCause());
		}
	}//end testCallTreatsServerErrorAsUpstreamDown()

	public function testCallWithMetaSurfacesBodyStatusDurationAndCorrelationId(): void {
		// The CallLog carries the upstream X-Correlation-ID header + the
		// OpenConnector-measured responseTime (ms) + the upstream status; the
		// router must surface them under `meta` alongside the decoded body so
		// a leaf can relay the Wet-BRP audit fields.
		$log = new FakeCallLog(
			200,
			[
				'statusCode' => 200,
				'responseTime' => 137.4,
				'headers' => [
					'Content-Type' => ['application/hal+json'],
					'X-Correlation-ID' => ['abcd-1234-correlation'],
				],
				'body' => '{"personen":[{"burgerservicenummer":"999993653"}]}',
				'encoding' => 'UTF-8',
			]
		);
		$router = $this->buildRouterWithCallLog($log);

		$result = $router->callWithMeta(new FakeExternalProvider(), 'POST', 'personen');

		$this->assertArrayHasKey('body', $result);
		$this->assertArrayHasKey('meta', $result);
		$this->assertSame('999993653', $result['body']['personen'][0]['burgerservicenummer']);
		$this->assertSame(200, $result['meta']['status']);
		$this->assertSame(137, $result['meta']['durationMs']);
		$this->assertSame('abcd-1234-correlation', $result['meta']['correlationId']);
		$this->assertSame('application/hal+json', $result['meta']['headers']['Content-Type']);
	}//end testCallWithMetaSurfacesBodyStatusDurationAndCorrelationId()

	public function testCallWithMetaFindsCorrelationIdCaseInsensitively(): void {
		$log = new FakeCallLog(
			200,
			[
				'statusCode' => 200,
				'responseTime' => 12,
				'headers' => ['x-correlation-id' => ['lower-case-id']],
				'body' => '{"personen":[]}',
				'encoding' => 'UTF-8',
			]
		);
		$router = $this->buildRouterWithCallLog($log);

		$result = $router->callWithMeta(new FakeExternalProvider(), 'POST', 'personen');
		$this->assertSame('lower-case-id', $result['meta']['correlationId']);
	}//end testCallWithMetaFindsCorrelationIdCaseInsensitively()

	public function testCallWithMetaDefaultsWhenHeadersAndTimingAbsent(): void {
		$log = new FakeCallLog(200, ['statusCode' => 200, 'body' => '{"personen":[]}', 'encoding' => 'UTF-8']);
		$router = $this->buildRouterWithCallLog($log);

		$result = $router->callWithMeta(new FakeExternalProvider(), 'POST', 'personen');
		$this->assertNull($result['meta']['correlationId']);
		$this->assertSame(0, $result['meta']['durationMs']);
		$this->assertSame(200, $result['meta']['status']);
		$this->assertSame([], $result['meta']['headers']);
	}//end testCallWithMetaDefaultsWhenHeadersAndTimingAbsent()

	public function testCallWithMetaDegradesOnAuthError(): void {
		$router = $this->buildRouterWithCallLog(new FakeCallLog(401, ['body' => 'denied']));

		try {
			$router->callWithMeta(new FakeExternalProvider(), 'POST', 'personen');
			$this->fail('Expected ProviderUnavailableException');
		} catch (ProviderUnavailableException $e) {
			$this->assertSame(ProviderUnavailableException::CAUSE_PROVIDER_AUTH, $e->getCause());
		}
	}//end testCallWithMetaDegradesOnAuthError()

	/**
	 * Build a router whose source is flagged `configuration.mock=true` and
	 * whose CallService explodes if reached — so a passing test proves the
	 * mock short-circuit fired without any real upstream call.
	 *
	 * @param array<string,mixed> $configuration The source `configuration` array.
	 *
	 * @return ExternalIntegrationRouter
	 */
	private function buildMockRouter(array $configuration): ExternalIntegrationRouter {
		$source = $this->sourceEntity(['configuration' => $configuration]);
		$sources = [
			'integriq/kvk' => $source,
			'integriq/brp-haalcentraal' => $source,
		];

		return $this->routerOn($this->objectService($sources), new ExplodingCallService());
	}//end buildMockRouter()

	public function testCallReturnsCannedMockBodyWithoutRealCall(): void {
		// A source flagged mock returns its canned mockResponse shaped exactly
		// like the real KvK upstream — and the ExplodingCallService proves no
		// real HTTP call was made.
		$fixture = [
			'resultaten' => [
				['kvkNummer' => '69599084', 'naam' => 'Conduction B.V.'],
				['kvkNummer' => '12345678', 'naam' => 'Acme Holding B.V.'],
			],
		];
		$router = $this->buildMockRouter(['mock' => true, 'mockResponse' => $fixture]);

		$result = $router->call(new FakeExternalProvider(source: 'kvk'), 'GET', 'zoeken');

		$this->assertSame($fixture, $result);
		$this->assertSame('69599084', $result['resultaten'][0]['kvkNummer']);
	}//end testCallReturnsCannedMockBodyWithoutRealCall()

	public function testCallReturnsEmptyBodyWhenMockResponseAbsent(): void {
		// Flagged mock but no fixture → empty body (never a 500); the leaf's
		// own extractor then yields an empty result set.
		$router = $this->buildMockRouter(['mock' => true]);

		$this->assertSame([], $router->call(new FakeExternalProvider(source: 'kvk'), 'GET', 'zoeken'));
	}//end testCallReturnsEmptyBodyWhenMockResponseAbsent()

	public function testCallWithMetaReturnsCannedBodyAndSynthesizedMeta(): void {
		// A BRP-style mock returns the canned personen body PLUS a synthesized
		// meta (status 200, non-zero duration, a fresh fake correlationId).
		$fixture = ['personen' => [['burgerservicenummer' => '999990019']]];
		$router = $this->buildMockRouter(['mock' => true, 'mockResponse' => $fixture]);

		$result = $router->callWithMeta(new FakeExternalProvider(source: 'brp-haalcentraal'), 'POST', 'personen');

		$this->assertSame($fixture, $result['body']);
		$this->assertSame('999990019', $result['body']['personen'][0]['burgerservicenummer']);
		$this->assertSame(200, $result['meta']['status']);
		$this->assertGreaterThan(0, $result['meta']['durationMs']);
		$this->assertNotNull($result['meta']['correlationId']);
		$this->assertSame($result['meta']['correlationId'], $result['meta']['headers']['X-Correlation-ID']);
	}//end testCallWithMetaReturnsCannedBodyAndSynthesizedMeta()

	public function testCallWithMetaHonoursMockMetaOverride(): void {
		$router = $this->buildMockRouter(
			[
				'mock' => true,
				'mockResponse' => ['personen' => []],
				'mockMeta' => ['status' => 200, 'durationMs' => 42, 'correlationId' => 'fixed-cid'],
			]
		);

		$result = $router->callWithMeta(new FakeExternalProvider(source: 'brp-haalcentraal'), 'POST', 'personen');

		$this->assertSame(42, $result['meta']['durationMs']);
		$this->assertSame('fixed-cid', $result['meta']['correlationId']);
	}//end testCallWithMetaHonoursMockMetaOverride()

	public function testNonMockSourceStillUsesTheRealCallPath(): void {
		// A source WITHOUT the mock flag must still hit the real CallService
		// path unchanged (here the FakeCallService returns a normal CallLog).
		$log = new FakeCallLog(200, ['statusCode' => 200, 'headers' => [], 'body' => '{"resultaten":[]}', 'encoding' => 'UTF-8']);
		$router = $this->buildRouterWithCallLog($log);

		$result = $router->call(new FakeExternalProvider(source: 'kvk'), 'GET', 'zoeken');

		$this->assertSame(['resultaten' => []], $result);
	}//end testNonMockSourceStillUsesTheRealCallPath()
}//end class
