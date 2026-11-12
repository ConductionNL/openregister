<?php

/**
 * Golden test: how ExternalIntegrationRouter reads what the connector answers.
 *
 * Pins the decoded body, the meta envelope and the failure classification for
 * every response shape the router accepts (plain arrays, JSON strings, scalars,
 * jsonSerialize objects, call-log objects with and without a body, base64 bodies,
 * status-only logs, header and timing variants), the mock-mode envelope, the
 * source configuration and the probe outcomes. Recorded against the router
 * before its response reading moved into ConnectorResponseReader.
 * Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Integration\UpstreamRequest;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * @covers \OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter
 */
final class ExternalIntegrationRouterResponseGoldenTest extends TestCase {

	/**
	 * The recorded answers.
	 *
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/external-integration-router-response-golden.json';

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$headers = ['x-correlation-id' => ['CID-1', 'CID-2'], 'Content-Type' => 'application/json', 'X-Empty' => []];
		$actual  = [
			'array response'                   => $this->both(
				response: ['a' => 1, 'statusCode' => 201, 'responseTime' => '12.6', 'headers' => $headers]
			),
			'array, numeric duration'          => $this->both(response: ['responseTime' => 7, 'headers' => 'none']),
			'array, non-numeric duration'      => $this->both(response: ['responseTime' => 'slow', 'headers' => ['X-Correlation-ID' => '']]),
			'json string'                      => $this->both(response: '{"x":[1,2]}'),
			'json scalar string'               => $this->both(response: '"text"'),
			'plain string'                     => $this->both(response: 'not json'),
			'integer'                          => $this->both(response: 42),
			'null'                             => $this->both(response: null),
			'serializable, array'              => $this->both(response: new GoldenSerializable(data: ['k' => 'v'])),
			'serializable, scalar'             => $this->both(response: new GoldenSerializable(data: 'flat')),
			'log, payload without body'        => $this->both(response: new GoldenCallLog(status: 200, payload: ['statusCode' => 200, 'data' => 1])),
			'log, json body'                   => $this->both(
				response: new GoldenCallLog(status: 0, payload: ['statusCode' => 202, 'body' => '{"ok":true}', 'responseTime' => 3.4, 'headers' => $headers])
			),
			'log, base64 body'                 => $this->both(
				response: new GoldenCallLog(status: 200, payload: ['body' => base64_encode('{"b":64}'), 'encoding' => 'base64'])
			),
			'log, base64 array body'           => $this->both(
				response: new GoldenCallLog(status: 200, payload: ['body' => ['raw' => 1], 'encoding' => 'base64'])
			),
			'log, bad base64'                  => $this->both(
				response: new GoldenCallLog(status: 200, payload: ['body' => '***', 'encoding' => 'base64'])
			),
			'log, null body'                   => $this->both(response: new GoldenCallLog(status: 200, payload: ['body' => null])),
			'log, no payload'                  => $this->both(response: new GoldenCallLog(status: 200, payload: null)),
			'log, 401'                         => $this->both(response: new GoldenCallLog(status: 401, payload: ['body' => 'no'])),
			'log, 403'                         => $this->both(response: new GoldenCallLog(status: 403, payload: null)),
			'log, 500'                         => $this->both(response: new GoldenCallLog(status: 500, payload: null)),
			'log, 399'                         => $this->both(response: new GoldenCallLog(status: 399, payload: null)),
			'entity log, response'             => $this->both(response: $this->entity(data: ['response' => ['statusCode' => 200, 'body' => '[1]']])),
			'entity log, status only'          => $this->both(response: $this->entity(data: ['statusCode' => 502])),
			'entity log, response status wins' => $this->both(
				response: $this->entity(data: ['statusCode' => 500, 'response' => ['statusCode' => 200]])
			),
			'entity log, non-numeric status'   => $this->both(response: $this->entity(data: ['statusCode' => 'x'])),
			'entity log, response not array'   => $this->both(response: $this->entity(data: ['response' => 'text'])),
			'connector throws'                 => $this->both(response: new \RuntimeException('socket closed')),
			'connector throws classified'      => $this->both(
				response: new ProviderUnavailableException(message: 'already', cause: ProviderUnavailableException::CAUSE_PROVIDER_AUTH)
			),
			'mock, canned body'                => $this->both(
				response: null,
				configuration: ['mock' => true, 'mockResponse' => ['m' => 1], 'mockMeta' => ['correlationId' => 'C-9']]
			),
			'mock, body not array'             => $this->both(
				response: null,
				configuration: ['mock' => true, 'mockResponse' => 'x', 'mockMeta' => ['correlationId' => 'C-9', 'status' => '204', 'durationMs' => 5.9]]
			),
			'mock, meta headers'               => $this->both(
				response: null,
				configuration: ['mock' => true, 'mockMeta' => ['correlationId' => 7, 'headers' => ['X-Correlation-ID' => 'kept', 'A' => ['1', '2']]]]
			),
			'mock, headers not array'          => $this->both(
				response: null,
				configuration: ['mock' => true, 'mockMeta' => ['correlationId' => 'C-1', 'headers' => 'x']]
			),
			'mock flag not true'               => $this->both(
				response: ['real' => true],
				configuration: ['mock' => 'yes', 'mockResponse' => ['m' => 1]]
			),
			'configuration'                    => $this->configuration(configuration: ['a' => 1]),
			'configuration not array'          => $this->configuration(configuration: 'x'),
			'configuration missing source'     => $this->configuration(configuration: null),
			'probe, local'                     => $this->probe(storage: 'magic-column', connector: true, sourceExists: true),
			'probe, connector missing'         => $this->probe(storage: 'external', connector: false, sourceExists: true),
			'probe, source missing'            => $this->probe(storage: 'external', connector: true, sourceExists: false),
			'probe, ok'                        => $this->probe(storage: 'external', connector: true, sourceExists: true),
		];

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$recorded = json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), true);
		$this->assertSame($expected, $recorded);
	}//end testScenariosMatchTheGolden()

	/**
	 * The answer of call() and of callWithMeta() for one connector response.
	 *
	 * @param mixed      $response      What the connector returns, or a throwable it throws.
	 * @param mixed|null $configuration The source configuration.
	 *
	 * @return array<string, mixed>
	 */
	private function both(mixed $response, mixed $configuration = []): array {
		$logger   = new GoldenLogger();
		$router   = $this->router(response: $response, configuration: $configuration, logger: $logger);
		$provider = new GoldenExternalProvider(storage: 'external');

		return [
			'call'     => $this->answer(run: static fn () => $router->call($provider, new UpstreamRequest(method: 'POST', path: 'items', options: ['query' => ['q' => 1]]))),
			'withMeta' => $this->answer(run: static fn () => $router->callWithMeta($provider, new UpstreamRequest(method: 'GET', path: 'items', options: []))),
			'logs'     => $logger->lines,
		];
	}//end both()

	/**
	 * The answer of sourceConfiguration().
	 *
	 * @param mixed $configuration The source configuration; null when the source is absent.
	 *
	 * @return array<string, mixed>
	 */
	private function configuration(mixed $configuration): array {
		$router = $this->router(response: null, configuration: $configuration, logger: new GoldenLogger());

		return ['answer' => $this->answer(run: static fn () => $router->sourceConfiguration(new GoldenExternalProvider(storage: 'external')))];
	}//end configuration()

	/**
	 * The answer of probe().
	 *
	 * @param string $storage      The provider's storage strategy.
	 * @param bool   $connector    Whether the connector app is enabled.
	 * @param bool   $sourceExists Whether the source exists.
	 *
	 * @return array<string, mixed>
	 */
	private function probe(string $storage, bool $connector, bool $sourceExists): array {
		$configuration = [];
		if ($sourceExists === false) {
			$configuration = null;
		}

		$router = $this->router(response: null, configuration: $configuration, logger: new GoldenLogger(), connector: $connector);
		return ['answer' => $router->probe(new GoldenExternalProvider(storage: $storage))];
	}//end probe()

	/**
	 * Run a call and record its result or the exception it threw.
	 *
	 * @param callable $run The call.
	 *
	 * @return mixed
	 */
	private function answer(callable $run): mixed {
		try {
			return ['result' => $run()];
		} catch (ProviderUnavailableException $e) {
			$previous = null;
			if ($e->getPrevious() !== null) {
				$previous = get_class($e->getPrevious());
			}

			return ['exception' => [ProviderUnavailableException::class, $e->getMessage(), $e->getCause(), $previous]];
		} catch (\Throwable $e) {
			return ['exception' => [get_class($e), $e->getMessage()]];
		}
	}//end answer()

	/**
	 * A call-log ObjectEntity.
	 *
	 * @param array<string, mixed> $data The log object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * A router whose source `golden` has the given configuration and whose connector answers `response`.
	 *
	 * @param mixed             $response      What the connector returns or throws.
	 * @param mixed             $configuration The source configuration; null for no source.
	 * @param GoldenLogger      $logger        Collects the logged lines.
	 * @param bool              $connector     Whether the connector app is enabled.
	 *
	 * @return ExternalIntegrationRouter
	 */
	private function router(mixed $response, mixed $configuration, GoldenLogger $logger, bool $connector = true): ExternalIntegrationRouter {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($connector);
		$appManager->method('isEnabledForUser')->willReturn($connector);

		$objectService = $this->getMockBuilder(ObjectService::class)->disableOriginalConstructor()->onlyMethods(['find'])->getMock();
		$objectService->method('find')->willReturnCallback(
			function () use ($configuration): ObjectEntity {
				if ($configuration === null) {
					throw new DoesNotExistException('Object not found');
				}

				return $this->entity(data: ['slug' => 'golden', 'location' => 'https://golden.example', 'configuration' => $configuration]);
			}
		);

		$callService = new GoldenCallService(response: $response);
		$container   = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match (true) {
				$id === \OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::class => $objectService,
				str_ends_with($id, 'CallService') => $callService,
				default => null,
			}
		);

		return new ExternalIntegrationRouter(appManager: $appManager, container: $container, logger: $logger);
	}//end router()
}//end class

/**
 * An external provider on source `golden`.
 */
final class GoldenExternalProvider extends AbstractIntegrationProvider {

	/**
	 * @param string $storage The storage strategy.
	 */
	public function __construct(private string $storage) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function getId(): string {
		return 'golden';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function getLabel(): string {
		return 'Golden';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function getIcon(): string {
		return 'Star';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string|null
	 */
	public function getRequiredApp(): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function getStorageStrategy(): string {
		return $this->storage;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function getOpenConnectorSource(): string {
		return 'golden';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function isEnabled(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 * @param string               $objectId The object id.
	 * @param array<string, mixed> $filters  The filters.
	 *
	 * @return array<int, mixed>
	 */
	public function list(ObjectRef $ref, array $filters = []): array {
		return [];
	}
}

/**
 * A connector CallService that returns, or throws, one preset response.
 */
final class GoldenCallService {

	/**
	 * @param mixed $response The response, or a throwable to throw.
	 */
	public function __construct(private mixed $response) {
	}

	/**
	 * @param mixed                $source   The source.
	 * @param string               $endpoint The endpoint.
	 * @param string               $method   The method.
	 * @param array<string, mixed> $config   The request options.
	 *
	 * @return mixed
	 */
	public function call($source, string $endpoint = '', string $method = 'GET', array $config = []) {
		if ($this->response instanceof \Throwable) {
			throw $this->response;
		}

		return $this->response;
	}
}

/**
 * A call log with a status code and a response payload.
 */
final class GoldenCallLog {

	/**
	 * @param int                       $status  The status code.
	 * @param array<string, mixed>|null $payload The response payload.
	 */
	public function __construct(private int $status, private ?array $payload) {
	}

	/**
	 * @return int
	 */
	public function getStatusCode(): int {
		return $this->status;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getResponse(): ?array {
		return $this->payload;
	}
}

/**
 * A JsonSerializable response.
 */
final class GoldenSerializable implements \JsonSerializable {

	/**
	 * @param mixed $data What jsonSerialize() answers.
	 */
	public function __construct(private mixed $data) {
	}

	/**
	 * @return mixed
	 */
	public function jsonSerialize(): mixed {
		return $this->data;
	}
}

/**
 * Records `level: message` lines.
 */
final class GoldenLogger extends AbstractLogger {

	/**
	 * The logged `level: message` lines.
	 *
	 * @var array<int, string>
	 */
	public array $lines = [];

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed               $level   The level.
	 * @param string|\Stringable $message The message.
	 * @param array<mixed>        $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|\Stringable $message, array $context = []): void {
		$this->lines[] = $level . ': ' . $message;
	}
}
