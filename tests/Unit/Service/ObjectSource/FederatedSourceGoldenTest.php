<?php

/**
 * Golden record of FederatedObjectSourceProvider.
 *
 * find(), findAll() and count() with a missing remote URL or share token,
 * padded config, paging and every search spelling, response bodies that are
 * records, errors, non-arrays or lists with non-array entries, uuid fallbacks
 * (`@self.id`, `id`, `uuid`), `@self.uri`, and a client that throws. Every
 * HTTP request and log line is recorded. The recording lives in
 * fixtures/federated-source-golden.json; set FEDERATED_SOURCE_GOLDEN_RECORD=1
 * to write it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
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
 * @spec exclude Pins the projection of the federated object source.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\FederatedObjectSourceProvider;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * The federated object source, recorded.
 *
 * @covers \OCA\OpenRegister\Service\ObjectSource\FederatedObjectSourceProvider
 */
class FederatedSourceGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/federated-source-golden.json';

	private const CONFIG = ['remoteUrl' => ' https://remote.example/ ', 'shareToken' => ' tok en/1 '];

	/**
	 * Everything observed: requests and log lines.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * A provider whose client answers every GET with $body (a Throwable is thrown).
	 *
	 * @param mixed $body The raw response body, or a Throwable.
	 *
	 * @return FederatedObjectSourceProvider The provider.
	 */
	private function provider(mixed $body): FederatedObjectSourceProvider {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $uri, array $options) use ($body): IResponse {
				$this->log[] = ['get', $uri, $options];
				if ($body instanceof \Throwable) {
					throw $body;
				}
				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($body);
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$log = &$this->log;
		$logger = new class($log) extends AbstractLogger {
			public function __construct(private array &$log) {
			}
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->log[] = ['log', $level, (string)$message];
			}
		};
		return new FederatedObjectSourceProvider($clients, $logger);
	}//end provider()

	/**
	 * Flatten what a call returned.
	 *
	 * @param mixed $value An entity, a list of entities, or a scalar.
	 *
	 * @return mixed The flattened value.
	 */
	private function flat(mixed $value): mixed {
		if ($value instanceof ObjectEntity) {
			return [$value->getUuid(), $value->getRegister(), $value->getSchema(), $value->getObject(), $value->getUri()];
		}
		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->flat($item), $value);
		}
		return $value;
	}//end flat()

	/**
	 * Run every scenario and record it.
	 *
	 * @return array<string, mixed> The recording.
	 */
	private function recordAll(): array {
		$register = new Register();
		$register->setId(5);
		$schema = new Schema();
		$schema->setId(50);
		$record = json_encode(['@self' => ['id' => 'self-id', 'uri' => 'https://remote.example/o/1'], 'id' => 'plain', 'name' => 'Full']);
		$list = json_encode(['results' => [
			['@self' => ['id' => 'a'], 'name' => 'A'],
			'not-an-array',
			['id' => 'b', 'uuid' => 'ignored'],
			['uuid' => 'c', '@self' => ['uri' => 7]],
			['name' => 'nameless'],
		], 'total' => 5]);

		$scenarios = [
			'find no config' => fn () => $this->provider($record)->find($register, $schema, 'x'),
			'find no token' => fn () => $this->provider($record)->find($register, $schema, 'x', ['remoteUrl' => 'https://r', 'shareToken' => '  ']),
			'find record' => fn () => $this->provider($record)->find($register, $schema, 'id/with space', self::CONFIG),
			'find error body' => fn () => $this->provider(json_encode(['error' => 'gone']))->find($register, $schema, 'x', self::CONFIG),
			'find scalar body' => fn () => $this->provider('"just a string"')->find($register, $schema, 'x', self::CONFIG),
			'find invalid json' => fn () => $this->provider('{not json')->find($register, $schema, 'x', self::CONFIG),
			'find client throws' => fn () => $this->provider(new RuntimeException('timeout'))->find($register, $schema, 'x', self::CONFIG),
			'findAll no config' => fn () => $this->provider($list)->findAll($register, $schema),
			'findAll defaults' => fn () => $this->provider($list)->findAll($register, $schema, [], self::CONFIG),
			'findAll paging and filters.search' => fn () => $this->provider($list)->findAll($register, $schema, ['limit' => 3, 'offset' => '6', 'filters' => ['search' => 'one'], '_search' => 'two', 'search' => 'three'], self::CONFIG),
			'findAll _search' => fn () => $this->provider($list)->findAll($register, $schema, ['_search' => 7, 'search' => 'three'], self::CONFIG),
			'findAll search' => fn () => $this->provider($list)->findAll($register, $schema, ['search' => 'three'], self::CONFIG),
			'findAll empty search' => fn () => $this->provider($list)->findAll($register, $schema, ['filters' => ['search' => ''], 'search' => 'x'], self::CONFIG),
			'findAll body without results' => fn () => $this->provider(json_encode(['total' => 0]))->findAll($register, $schema, [], self::CONFIG),
			'findAll scalar body' => fn () => $this->provider('12')->findAll($register, $schema, [], self::CONFIG),
			'findAll client throws' => fn () => $this->provider(new RuntimeException('refused'))->findAll($register, $schema, [], self::CONFIG),
			'count' => fn () => $this->provider($list)->count($register, $schema, ['limit' => 10], self::CONFIG),
		];

		$recording = [];
		foreach ($scenarios as $name => $run) {
			$this->log = [];
			$result = $this->flat($run());
			$recording[$name] = ['result' => $result, 'observed' => $this->log];
		}
		return $recording;
	}//end recordAll()

	/**
	 * Every scenario matches the recording.
	 *
	 * @return void
	 */
	public function testMatchesTheGoldenRecord(): void {
		$recording = $this->recordAll();
		if (getenv('FEDERATED_SOURCE_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($recording, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
		}
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($recording), true));
	}//end testMatchesTheGoldenRecord()
}//end class
